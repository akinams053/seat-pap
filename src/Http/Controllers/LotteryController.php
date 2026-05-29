<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Kassie\Calendar\Models\Lottery;
use Seat\Kassie\Calendar\Models\LotteryNode;
use Seat\Kassie\Calendar\Models\LotteryPrize;
use Seat\Kassie\Calendar\Models\Operation;
use Seat\Kassie\Calendar\Models\Pap;
use Seat\Kassie\Calendar\Models\PapAdjustment;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;

/**
 * PAP 超网抽奖控制器
 * 阶段 2 创建与展示 / 阶段 3 购买扣费 / 阶段 4 手动开奖 / 阶段 5 取消退款
 */
class LotteryController extends Controller
{
    /**
     * 可用 PAP 固定起始日（与现有 PAP 统计 / 商店 API 口径一致，见计划 §5.1）
     */
    private const PAP_SINCE = '2026-01-01';

    public function __construct()
    {
        $this->middleware('can:calendar.view')->only(['index', 'show', 'purchase']);
        $this->middleware('can:calendar.create')->only(['create', 'store', 'draw', 'cancel']);
    }

    /**
     * 抽奖列表页
     */
    public function index(): Factory|View
    {
        $lotteries = Lottery::with('operation')
            ->withCount([
                // reorder() 清掉关系自带的 orderBy，避免 count 子查询带 ORDER BY 报错
                'prizes' => fn($q) => $q->reorder(),
                'nodes as sold_nodes_count' => fn($q) => $q->reorder()
                    ->whereNotNull('purchased_at')
                    ->whereNull('refunded_at')
                    ->whereNull('voided_at'),
            ])
            ->orderByDesc('id')
            ->get();

        return view('calendar::lottery.index', [
            'lotteries' => $lotteries,
            'can_manage' => auth()->user()->can('calendar.create'),
        ]);
    }

    /**
     * 创建抽奖表单页
     */
    public function create(): Factory|View
    {
        return view('calendar::lottery.create');
    }

    /**
     * 创建抽奖：建特殊 operation + 绑定保留 tag + lottery / prizes / nodes（见计划 §2.1）
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'node_count' => 'required|integer|min:1|max:10000',
            'node_price' => 'required|numeric|min:0.01|max:999999.99',
            'max_nodes_per_user' => 'nullable|integer|min:1',
            'allow_repeat_winners' => 'nullable|boolean',
            'prizes' => 'required|array|min:1',
            'prizes.*.name' => 'required|string|max:255',
            'prizes.*.description' => 'nullable|string|max:2000',
        ]);

        // §1.5：每人上限不得超过总节点数
        if (! empty($validated['max_nodes_per_user'])
            && $validated['max_nodes_per_user'] > $validated['node_count']) {
            return back()->withInput()->with('error', trans('calendar::lottery.err_limit_gt_total'));
        }

        $user = auth()->user();
        $mainCharId = $user->main_character_id;
        if (empty($mainCharId)) {
            return back()->withInput()->with('error', trans('calendar::lottery.err_no_main_character'));
        }

        $lottery = DB::transaction(function () use ($validated, $user, $mainCharId): Lottery {
            // 1. 创建抽奖专用 operation（标题前缀【抽奖】）
            $operation = new Operation([
                'title' => '【抽奖】' . $validated['title'],
                'fc' => optional($user->main_character)->name ?? $user->name,
                'fc_character_id' => $mainCharId,
                'importance' => 0,
            ]);
            $operation->start_at = carbon();
            $operation->end_at = carbon();
            $operation->user()->associate($user);
            $operation->save();

            // 2. 绑定系统保留的抽奖专用 tag（quantifier=0，保证基础 PAP 为 0）
            $operation->tags()->attach(Lottery::reservedTag()->id);

            // 3. 抽奖主记录，创建即 open（第一版不做 draft）
            $lottery = Lottery::create([
                'operation_id' => $operation->id,
                'title' => $validated['title'],
                'node_count' => $validated['node_count'],
                'node_price' => $validated['node_price'],
                'max_nodes_per_user' => $validated['max_nodes_per_user'] ?? null,
                'allow_repeat_winners' => (bool) ($validated['allow_repeat_winners'] ?? false),
                'status' => 'open',
                'created_by_character_id' => $mainCharId,
            ]);

            // 4. 奖品
            foreach (array_values($validated['prizes']) as $i => $prize) {
                LotteryPrize::create([
                    'lottery_id' => $lottery->id,
                    'sort_order' => $i,
                    'name' => $prize['name'],
                    'description' => $prize['description'] ?? null,
                ]);
            }

            // 5. 预生成全部节点（批量插入）
            $nodes = [];
            for ($n = 1; $n <= $validated['node_count']; $n++) {
                $nodes[] = ['lottery_id' => $lottery->id, 'node_number' => $n];
            }
            foreach (array_chunk($nodes, 500) as $chunk) {
                LotteryNode::insert($chunk);
            }

            return $lottery;
        });

        return redirect()->route('lottery.show', ['lottery' => $lottery->id])
            ->with('success', trans('calendar::lottery.created_success'));
    }

    /**
     * 抽奖详情页（阶段 2：展示奖品、节点、进度；购买区在阶段 3 完善）
     */
    public function show(int $lottery): Factory|View
    {
        $model = Lottery::with(['operation', 'prizes', 'nodes'])->find($lottery);
        if (is_null($model)) {
            abort(404);
        }

        // 节点归属 + 中奖者 + 开奖人，统一解析角色名
        $charIds = $model->nodes->pluck('character_id')
            ->merge($model->prizes->pluck('winner_character_id'))
            ->push($model->drawn_by_character_id)
            ->filter()
            ->unique();
        $charNames = CharacterInfo::whereIn('character_id', $charIds)->pluck('name', 'character_id');

        $soldCount = $model->nodes
            ->filter(fn($n) => ! is_null($n->purchased_at) && is_null($n->refunded_at) && is_null($n->voided_at))
            ->count();

        $user = auth()->user();
        // 当前用户在本抽奖里有效持有的节点数（按 user_id 聚合所有 alt）
        $myOwned = $model->nodes
            ->filter(fn($n) => $n->user_id == $user->id
                && ! is_null($n->purchased_at) && is_null($n->refunded_at) && is_null($n->voided_at))
            ->count();

        return view('calendar::lottery.show', [
            'lottery' => $model,
            'char_names' => $charNames,
            'sold_count' => $soldCount,
            'remaining_count' => $model->node_count - $soldCount,
            'my_user_id' => $user->id,
            'my_owned' => $myOwned,
            'available_pap' => $this->availablePap($user),
            'can_manage' => $user->can('calendar.create'),
        ]);
    }

    /**
     * 购买节点（阶段 3 核心，见计划 §6.1）
     *
     * 全程在事务内、锁定 lottery 主行后串行处理：
     * 重算余额（不 floor）→ 校验上限/剩余 → 随机选号 → 写负数 PapAdjustment → recompute。
     */
    public function purchase(Request $request, int $lottery): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);
        $quantity = (int) $validated['quantity'];

        $user = auth()->user();
        $mainCharId = $user->main_character_id;
        if (empty($mainCharId)) {
            return response()->json([
                'status' => 'error',
                'message' => trans('calendar::lottery.err_no_main_character'),
            ], 422);
        }
        $charIds = $user->associatedCharacterIds();

        $result = DB::transaction(function () use ($lottery, $quantity, $user, $mainCharId, $charIds): array {
            // 1. 锁定 lottery 主行（串行化同一抽奖的并发购买）
            $model = Lottery::lockForUpdate()->find($lottery);
            if (is_null($model)) {
                return ['error' => trans('calendar::lottery.err_not_found'), 'code' => 404];
            }

            // 2. 状态必须 open
            if ($model->status !== 'open') {
                return ['error' => trans('calendar::lottery.err_not_open')];
            }

            // 3. 锁内取未售节点
            $unsold = LotteryNode::where('lottery_id', $model->id)
                ->whereNull('user_id')
                ->whereNull('purchased_at')
                ->lockForUpdate()
                ->get(['id', 'node_number']);

            if ($quantity > $unsold->count()) {
                return ['error' => trans('calendar::lottery.err_not_enough_nodes', ['remaining' => $unsold->count()])];
            }

            // 4. 每人上限（锁内重算，按 user_id 聚合）
            if (! is_null($model->max_nodes_per_user)) {
                $owned = LotteryNode::where('lottery_id', $model->id)
                    ->where('user_id', $user->id)
                    ->whereNotNull('purchased_at')
                    ->whereNull('refunded_at')
                    ->whereNull('voided_at')
                    ->count();
                if ($owned + $quantity > $model->max_nodes_per_user) {
                    return ['error' => trans('calendar::lottery.err_exceed_limit', [
                        'limit' => $model->max_nodes_per_user,
                        'owned' => $owned,
                    ])];
                }
            }

            // 5. 余额：事务内重算，不 floor 到 0（§11.6）
            $totalPrice = round((float) $model->node_price * $quantity, 2);
            $available = (float) DB::table('kassie_calendar_paps')
                ->whereIn('character_id', $charIds)
                ->where('join_time', '>=', self::PAP_SINCE)
                ->sum('value');
            if ($available < $totalPrice) {
                return ['error' => trans('calendar::lottery.err_insufficient_pap', [
                    'available' => number_format($available, 2),
                    'need' => number_format($totalPrice, 2),
                ])];
            }

            // 6. 随机选号（random_int 公平抽取）
            $chosen = $this->randomPick($unsold->all(), $quantity);
            $chosenIds = array_map(fn($n) => $n->id, $chosen);
            $chosenNumbers = array_map(fn($n) => (int) $n->node_number, $chosen);
            sort($chosenNumbers);

            $operationId = $model->operation_id;
            $now = carbon();

            // 7. 确保抽奖 operation 下该主角色的 Pap 行存在（首次 save，value=0；之后只 recompute，见 §11.5）
            Pap::firstOrCreate(
                ['operation_id' => $operationId, 'character_id' => $mainCharId],
                [
                    // ship_type_id 在 paps 表是 NOT NULL 无默认；抽奖非真实舰队、无船型，占位 0（审查弹窗显示为 —）
                    'ship_type_id' => 0,
                    'join_time' => $now->toDateTimeString(),
                    'created_at' => $now,
                ]
            );

            // 8. 负数聚合扣费记录
            $nodeListStr = implode(', ', array_map(
                fn($n) => '#' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                $chosenNumbers
            ));
            $adjustment = PapAdjustment::create([
                'operation_id' => $operationId,
                'character_id' => $mainCharId,
                'value' => -$totalPrice,
                'reason' => trans('calendar::lottery.purchase_reason', [
                    'count' => $quantity,
                    'nodes' => $nodeListStr,
                ]),
                'created_by_character_id' => $mainCharId,
                'created_at' => $now,
            ]);

            // 更新选中节点归属（whereNull user_id 双保险）
            $updated = LotteryNode::whereIn('id', $chosenIds)
                ->whereNull('user_id')
                ->update([
                    'user_id' => $user->id,
                    'character_id' => $mainCharId,
                    'pap_adjustment_id' => $adjustment->id,
                    'purchased_at' => $now,
                ]);

            // 节点更新数与预期不符：抛异常整体回滚（不应发生，锁内已串行）
            if ($updated !== $quantity) {
                throw new \RuntimeException('Lottery node allocation mismatch.');
            }

            // 9. 回写 paps.value（绝不再 plain save Pap）
            Pap::recomputeValueFor($operationId, $mainCharId);

            // 售罄判定
            $soldNow = LotteryNode::where('lottery_id', $model->id)
                ->whereNotNull('purchased_at')
                ->whereNull('refunded_at')
                ->whereNull('voided_at')
                ->count();
            if ($soldNow >= $model->node_count) {
                $model->status = 'sold_out';
                $model->save();
            }

            return [
                'ok' => true,
                'chosen' => $chosenNumbers,
                'spent' => $totalPrice,
                'sold_count' => $soldNow,
                'lottery_status' => $model->status,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
            ], $result['code'] ?? 422);
        }

        return response()->json([
            'status' => 'success',
            'chosen' => $result['chosen'],
            'spent' => $result['spent'],
            'sold_count' => $result['sold_count'],
            'lottery_status' => $result['lottery_status'],
            // 购买后重算可用 PAP（不 floor）
            'available_pap' => $this->availablePap($user),
            'message' => trans('calendar::lottery.purchase_success', [
                'count' => count($result['chosen']),
                'spent' => number_format($result['spent'], 2),
            ]),
        ]);
    }

    /**
     * 售完后手动开奖（阶段 4，见计划 §2.4 / §1.7 / §6.5）
     *
     * 仅 sold_out 可开奖；事务内锁主行重确认状态，多奖按顺序抽，
     * 单个中奖节点移出后续池；不允许重复时该用户全部节点移出。
     */
    public function draw(int $lottery): JsonResponse
    {
        $drawnByCharId = auth()->user()->main_character_id;

        $result = DB::transaction(function () use ($lottery, $drawnByCharId): array {
            // 锁主行并重确认状态（幂等：已 drawn 不可再开，见 §6.3）
            $model = Lottery::lockForUpdate()->find($lottery);
            if (is_null($model)) {
                return ['error' => trans('calendar::lottery.err_not_found'), 'code' => 404];
            }
            // 允许 sold_out 正常开奖，也允许 open 状态下凑不满人时由 FC 提前开奖
            if (! in_array($model->status, ['open', 'sold_out'], true)) {
                return ['error' => trans('calendar::lottery.err_not_drawable')];
            }
            $isEarly = $model->status === 'open';

            // 有效节点池（已购、未退、未作废）
            $nodes = LotteryNode::where('lottery_id', $model->id)
                ->whereNotNull('purchased_at')
                ->whereNull('refunded_at')
                ->whereNull('voided_at')
                ->get(['id', 'node_number', 'user_id', 'character_id']);

            // 提前开奖至少要有一个已售节点，否则无候选、开奖无意义
            if ($nodes->isEmpty()) {
                return ['error' => trans('calendar::lottery.err_no_sold_nodes')];
            }

            $prizes = LotteryPrize::where('lottery_id', $model->id)
                ->orderBy('sort_order')
                ->get();

            $allowRepeat = (bool) $model->allow_repeat_winners;
            $usedNodeIds = [];        // 已中奖的节点（任何模式下都不再参与）
            $excludedUserIds = [];    // 不允许重复时，已中奖用户的全部节点排除
            $rounds = [];
            $now = carbon();

            foreach ($prizes as $prize) {
                $candidates = $nodes->filter(function ($n) use ($usedNodeIds, $allowRepeat, $excludedUserIds) {
                    if (in_array($n->id, $usedNodeIds, true)) {
                        return false;
                    }
                    if (! $allowRepeat && in_array($n->user_id, $excludedUserIds, true)) {
                        return false;
                    }

                    return true;
                })->values();

                // 候选为空（如不允许重复时奖品数多于中奖人数）：该奖品留空
                if ($candidates->isEmpty()) {
                    $rounds[] = [
                        'prize_id' => $prize->id,
                        'prize_name' => $prize->name,
                        'candidate_node_count' => 0,
                        'roll_index' => null,
                        'winner_node_number' => null,
                        'winner_user_id' => null,
                        'winner_character_id' => null,
                    ];
                    continue;
                }

                $rollIndex = random_int(0, $candidates->count() - 1);
                $winner = $candidates[$rollIndex];

                $prize->winner_node_number = (int) $winner->node_number;
                $prize->winner_user_id = $winner->user_id;
                $prize->winner_character_id = $winner->character_id;
                $prize->drawn_at = $now;
                $prize->save();

                $usedNodeIds[] = $winner->id;
                if (! $allowRepeat) {
                    $excludedUserIds[] = $winner->user_id;
                }

                $rounds[] = [
                    'prize_id' => $prize->id,
                    'prize_name' => $prize->name,
                    'candidate_node_count' => $candidates->count(),
                    'roll_index' => $rollIndex,
                    'winner_node_number' => (int) $winner->node_number,
                    'winner_user_id' => $winner->user_id,
                    'winner_character_id' => $winner->character_id,
                ];
            }

            // 提前开奖：把未售节点作废，避免 drawn 后仍残留“可购”语义
            if ($isEarly) {
                LotteryNode::where('lottery_id', $model->id)
                    ->whereNull('purchased_at')
                    ->update(['voided_at' => $now]);
            }

            $model->status = 'drawn';
            $model->drawn_by_character_id = $drawnByCharId;
            $model->drawn_at = $now;
            $model->draw_log = [
                'draw_mode' => $isEarly ? 'early' : 'sold_out',
                'allow_repeat_winners' => $allowRepeat,
                'rounds' => $rounds,
            ];
            $model->save();

            return ['ok' => true];
        });

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
            ], $result['code'] ?? 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => trans('calendar::lottery.draw_success'),
        ]);
    }

    /**
     * FC / 管理员取消并退款（阶段 5，见计划 §2.5 / §6.6）
     *
     * 仅 open / sold_out 且未开奖可取消；退款不删原扣费，
     * 按用户聚合追加正数 PapAdjustment 并回写 paps.value，状态转 cancelled。
     */
    public function cancel(int $lottery): JsonResponse
    {
        $operatorCharId = auth()->user()->main_character_id;

        $result = DB::transaction(function () use ($lottery, $operatorCharId): array {
            // 锁主行并重确认状态（幂等：已 cancelled / drawn 不可再退，见 §6.3）
            $model = Lottery::lockForUpdate()->find($lottery);
            if (is_null($model)) {
                return ['error' => trans('calendar::lottery.err_not_found'), 'code' => 404];
            }
            if (! in_array($model->status, ['open', 'sold_out'], true)) {
                return ['error' => trans('calendar::lottery.err_not_cancellable')];
            }

            // 锁定有效持有节点（已购、未退、未作废）
            $nodes = LotteryNode::where('lottery_id', $model->id)
                ->whereNotNull('purchased_at')
                ->whereNull('refunded_at')
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->get(['id', 'node_number', 'user_id', 'character_id']);

            $operationId = $model->operation_id;
            $now = carbon();
            $price = (float) $model->node_price;

            // 按购买主角色聚合退款（与扣费口径一致：节点数 × 单价）
            $byChar = $nodes->groupBy('character_id');
            foreach ($byChar as $charId => $charNodes) {
                $count = $charNodes->count();
                $refund = round($price * $count, 2);
                if ($refund <= 0) {
                    continue;
                }

                $numbers = $charNodes->pluck('node_number')->sort()->values()
                    ->map(fn($n) => '#' . str_pad((string) $n, 2, '0', STR_PAD_LEFT))
                    ->implode(', ');

                PapAdjustment::create([
                    'operation_id' => $operationId,
                    'character_id' => (int) $charId,
                    'value' => $refund, // 正数退款
                    'reason' => trans('calendar::lottery.refund_reason', ['nodes' => $numbers]),
                    'created_by_character_id' => $operatorCharId,
                    'created_at' => $now,
                ]);

                Pap::recomputeValueFor($operationId, (int) $charId);
            }

            // 标记节点已退款（防重复退款）
            LotteryNode::whereIn('id', $nodes->pluck('id'))
                ->update(['refunded_at' => $now]);

            $model->status = 'cancelled';
            $model->save();

            return ['ok' => true, 'refunded_nodes' => $nodes->count()];
        });

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
            ], $result['code'] ?? 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => trans('calendar::lottery.cancel_success', ['count' => $result['refunded_nodes']]),
        ]);
    }

    /**
     * 抽奖可用 PAP：复用现有统计口径（join_time >= 起始日、按 alt 聚合），
     * 但不 floor 到 0——透支必须照实表达（§11.6）。
     */
    private function availablePap(User $user): float
    {
        return (float) DB::table('kassie_calendar_paps')
            ->whereIn('character_id', $user->associatedCharacterIds())
            ->where('join_time', '>=', self::PAP_SINCE)
            ->sum('value');
    }

    /**
     * 从候选集中用 random_int 公平抽取 n 个（部分 Fisher-Yates）
     *
     * @param  array  $items
     * @return array
     */
    private function randomPick(array $items, int $n): array
    {
        $count = count($items);
        for ($i = 0; $i < $n && $i < $count; $i++) {
            $j = random_int($i, $count - 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_slice($items, 0, $n);
    }
}
