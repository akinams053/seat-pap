<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Kassie\Calendar\Models\Lottery;
use Seat\Kassie\Calendar\Models\LotteryNode;
use Seat\Kassie\Calendar\Models\LotteryPrize;
use Seat\Kassie\Calendar\Models\Operation;
use Seat\Web\Http\Controllers\Controller;

/**
 * PAP 超网抽奖控制器（阶段 2：创建与展示）
 *
 * 购买 / 开奖 / 取消退款分别在阶段 3 / 4 / 5 补充。
 */
class LotteryController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:calendar.view')->only(['index', 'show']);
        $this->middleware('can:calendar.create')->only(['create', 'store']);
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

        $charIds = $model->nodes->pluck('character_id')->filter()->unique();
        $charNames = CharacterInfo::whereIn('character_id', $charIds)->pluck('name', 'character_id');

        $soldCount = $model->nodes
            ->filter(fn($n) => ! is_null($n->purchased_at) && is_null($n->refunded_at) && is_null($n->voided_at))
            ->count();

        return view('calendar::lottery.show', [
            'lottery' => $model,
            'char_names' => $charNames,
            'sold_count' => $soldCount,
            'my_user_id' => auth()->user()->id,
            'can_manage' => auth()->user()->can('calendar.create'),
        ]);
    }
}
