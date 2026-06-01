<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Sde\MapDenormalize;
use Seat\Kassie\Calendar\Models\Operation;
use Seat\Kassie\Calendar\Models\Pap;
use Seat\Kassie\Calendar\Models\PapAdjustment;
use Seat\Web\Http\Controllers\Controller;

class AuditController extends Controller
{
    /**
     * 列名 → 排序用 SQL 表达式（白名单防注入）
     * fleet_end_at 用 COALESCE 与显示逻辑保持一致：
     * 优先 MAX(paps.created_at)，无值时回退 o.end_at
     */
    private const SORT_COLUMNS = [
        'title'        => 'o.title',
        'fleet_end_at' => 'COALESCE(MAX(p.created_at), o.end_at)',
        'pap_value'    => 'MAX(t.quantifier)',
        'member_count' => 'COUNT(DISTINCT p.character_id)',
        'pap_total'    => 'SUM(p.value)',
    ];

    /**
     * 行动审查列表页
     */
    public function index(): Factory|View
    {
        return view('calendar::audit.index');
    }

    /**
     * DataTables 服务端：列出已发 PAP 的行动
     */
    public function operationsJson(Request $request): JsonResponse
    {
        $canAudit = auth()->user()->can('calendar.create');

        $base = DB::table('calendar_operations as o')
            ->join('kassie_calendar_paps as p', 'p.operation_id', '=', 'o.id')
            ->leftJoin('calendar_tag_operation as tx', 'tx.operation_id', '=', 'o.id')
            ->leftJoin('calendar_tags as t', 't.id', '=', 'tx.tag_id')
            ->select(
                'o.id',
                'o.title',
                'o.fc',
                'o.fc_character_id',
                'o.end_at',
                DB::raw('MAX(p.created_at) as latest_pap_at'),
                DB::raw('COUNT(DISTINCT p.character_id) as member_count'),
                DB::raw('COALESCE(SUM(p.value), 0) as pap_total'),
                DB::raw('COALESCE(MAX(t.quantifier), 0) as pap_value')
            )
            ->where('o.is_consumption', 0)
            ->groupBy('o.id', 'o.title', 'o.fc', 'o.fc_character_id', 'o.end_at');

        $totalCount = DB::table('calendar_operations as o')
            ->join('kassie_calendar_paps as p', 'p.operation_id', '=', 'o.id')
            ->where('o.is_consumption', 0)
            ->distinct()
            ->count('o.id');

        // DataTables 排序参数：order[0][column]=列序号，columns[N][data]=列字段名，order[0][dir]=asc/desc
        $orderColIdx = (int) $request->input('order.0.column', 1);
        $orderDir = strtolower((string) $request->input('order.0.dir', 'desc'));
        $orderDir = in_array($orderDir, ['asc', 'desc'], true) ? $orderDir : 'desc';
        $orderColData = (string) $request->input("columns.$orderColIdx.data", 'fleet_end_at');
        $orderExpr = self::SORT_COLUMNS[$orderColData] ?? 'MAX(p.created_at)';

        $rows = $base
            ->orderByRaw("$orderExpr IS NULL, $orderExpr $orderDir")
            ->offset((int) $request->input('start', 0))
            ->limit((int) $request->input('length', 25))
            ->get();

        $data = $rows->map(fn($r) => [
            'id' => $r->id,
            'title' => $r->title,
            'fc' => $r->fc,
            'fc_character_id' => $r->fc_character_id,
            'fleet_end_at' => $r->latest_pap_at ?: ($r->end_at ?: null),
            'member_count' => (int) $r->member_count,
            'pap_value' => (float) $r->pap_value,
            'pap_total' => (float) $r->pap_total,
            'can_audit' => $canAudit,
        ]);

        return response()->json([
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $totalCount,
            'recordsFiltered' => $totalCount,
            'data' => $data,
        ]);
    }

    /**
     * 成员明细 JSON：返回该 op 的全员 PAP 快照 + 调整记录
     */
    public function membersJson(int $operationId): JsonResponse
    {
        $operation = Operation::with('tags')->find($operationId);
        if (is_null($operation))
            return response()->json(['status' => 'error', 'message' => 'Operation not found.'], 404);

        if (!$operation->isUserGranted(auth()->user()))
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);

        $paps = Pap::where('operation_id', $operationId)
            ->orderBy('join_time')
            ->get();

        $charNames = CharacterInfo::whereIn('character_id', $paps->pluck('character_id'))
            ->pluck('name', 'character_id');
        $shipNames = InvType::whereIn('typeID', $paps->pluck('ship_type_id')->filter())
            ->pluck('typeName', 'typeID');
        $sysNames = MapDenormalize::whereIn('itemID', $paps->pluck('solar_system_id')->filter())
            ->pluck('itemName', 'itemID');

        $adjustments = PapAdjustment::where('operation_id', $operationId)
            ->orderBy('created_at')
            ->get()
            ->groupBy('character_id');
        $adjusterNames = CharacterInfo::whereIn('character_id', $adjustments->flatten()->pluck('created_by_character_id'))
            ->pluck('name', 'character_id');

        $analytics = $operation->tags->pluck('analytics')->filter()->unique()->map(
            fn($a) => trans('calendar::seat.' . $a) !== 'calendar::seat.' . $a ? trans('calendar::seat.' . $a) : $a
        )->implode(', ') ?: '—';
        $papValue = (float) ($operation->tags->max('quantifier') ?: 0);

        $members = $paps->map(fn($p) => [
            'character_id' => $p->character_id,
            'character_name' => $charNames->get($p->character_id, '#' . $p->character_id),
            'ship_type_id' => $p->ship_type_id,
            'ship_name' => $shipNames->get($p->ship_type_id, '—'),
            'solar_system_id' => $p->solar_system_id,
            'solar_system_name' => $p->solar_system_id ? $sysNames->get($p->solar_system_id, '—') : null,
            'join_time' => $p->join_time,
            'value' => (float) $p->value,
            'adjustments' => ($adjustments->get($p->character_id) ?? collect())->map(fn($a) => [
                'value' => (float) $a->value,
                'reason' => $a->reason,
                'by_name' => $adjusterNames->get($a->created_by_character_id, '#' . $a->created_by_character_id),
                'at' => optional($a->created_at)->toDateTimeString(),
            ])->values(),
        ]);

        return response()->json([
            'status' => 'success',
            'operation' => [
                'id' => $operation->id,
                'title' => $operation->title,
                'fc' => $operation->fc,
                'pap_value' => $papValue,
                'analytics' => $analytics,
                'fleet_end_at' => optional($paps->max('created_at'))->toDateTimeString() ?: optional($operation->end_at)->toDateTimeString(),
                'member_count' => $paps->count(),
                'pap_total' => (float) $paps->sum('value'),
            ],
            'can_audit' => auth()->user()->can('calendar.create'),
            'members' => $members,
        ]);
    }

    /**
     * 通过追加反向 adjustment，将本行动现有成员的最终 PAP 清零。
     */
    public function zero(int $operationId): JsonResponse
    {
        if (!auth()->user()->can('calendar.create'))
            return response()->json(['status' => 'error', 'message' => 'Permission denied.'], 403);

        $operation = Operation::find($operationId);
        if (is_null($operation))
            return response()->json(['status' => 'error', 'message' => 'Operation not found.'], 404);

        if (!$operation->isUserGranted(auth()->user()))
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);

        $operatorCharId = auth()->user()->main_character_id;
        $reason = trans('calendar::paps.audit_zero_reason');

        $result = DB::transaction(function () use ($operationId, $operatorCharId, $reason): array {
            $paps = Pap::where('operation_id', $operationId)
                ->lockForUpdate()
                ->get(['character_id', 'value']);

            $adjusted = 0;
            $skipped = 0;

            foreach ($paps as $pap) {
                $currentValue = round((float) $pap->value, 2);
                if (abs($currentValue) < 0.005) {
                    $skipped++;
                    continue;
                }

                PapAdjustment::create([
                    'operation_id' => $operationId,
                    'character_id' => $pap->character_id,
                    'value' => -$currentValue,
                    'reason' => $reason,
                    'created_by_character_id' => $operatorCharId,
                    'created_at' => carbon(),
                ]);

                Pap::recomputeValueFor($operationId, (int) $pap->character_id);
                $adjusted++;
            }

            return [
                'adjusted' => $adjusted,
                'skipped' => $skipped,
            ];
        });

        if ($result['adjusted'] === 0) {
            return response()->json([
                'status' => 'success',
                'adjusted_count' => 0,
                'skipped_count' => $result['skipped'],
                'message' => trans('calendar::paps.audit_zero_no_changes'),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'adjusted_count' => $result['adjusted'],
            'skipped_count' => $result['skipped'],
            'message' => trans('calendar::paps.audit_zero_success', [
                'adjusted' => $result['adjusted'],
                'skipped' => $result['skipped'],
            ]),
        ]);
    }

    /**
     * 写入奖惩记录并回写 paps.value
     * 权限：calendar.create + 行动可见性
     */
    public function adjust(Request $request, int $operationId): JsonResponse
    {
        if (!auth()->user()->can('calendar.create'))
            return response()->json(['status' => 'error', 'message' => 'Permission denied.'], 403);

        $operation = Operation::with('tags')->find($operationId);
        if (is_null($operation))
            return response()->json(['status' => 'error', 'message' => 'Operation not found.'], 404);

        if (!$operation->isUserGranted(auth()->user()))
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);

        $validated = $request->validate([
            'character_id' => 'required|integer',
            'value' => 'required|numeric|min:0.01',
            'direction' => 'required|in:award,deduct',
            'reason' => 'required|string|max:255',
        ]);

        $exists = Pap::where('operation_id', $operationId)
            ->where('character_id', $validated['character_id'])
            ->exists();
        if (!$exists)
            return response()->json([
                'status' => 'error',
                'message' => trans('calendar::paps.audit_not_in_fleet'),
            ], 422);

        $signed = $validated['direction'] === 'deduct' ? -$validated['value'] : $validated['value'];
        $operatorCharId = auth()->user()->main_character_id;

        $adjustment = PapAdjustment::create([
            'operation_id' => $operationId,
            'character_id' => $validated['character_id'],
            'value' => $signed,
            'reason' => $validated['reason'],
            'created_by_character_id' => $operatorCharId,
            'created_at' => carbon(),
        ]);

        Pap::recomputeValueFor($operationId, (int) $validated['character_id']);

        $newValue = (float) DB::table('kassie_calendar_paps')
            ->where('operation_id', $operationId)
            ->where('character_id', $validated['character_id'])
            ->value('value');

        $byName = $operatorCharId
            ? (CharacterInfo::find($operatorCharId)?->name ?? '#' . $operatorCharId)
            : '—';

        return response()->json([
            'status' => 'success',
            'new_value' => $newValue,
            'adjustment' => [
                'value' => (float) $adjustment->value,
                'reason' => $adjustment->reason,
                'by_name' => $byName,
                'at' => $adjustment->created_at->toDateTimeString(),
            ],
        ]);
    }

    /**
     * 外部消费审查页（资金审查）：玩法审查归外部服务，本页只做资金审查。
     */
    public function consumptionIndex(): Factory|View
    {
        return view('calendar::audit.consumption');
    }

    /**
     * 消费场次聚合：按 ref_group（回退 op:operation_id）汇总成「一笔订单/场次」。
     * 数据源 = source <> attendance_audit 的全部账本调整（含历史抽奖、商店、新消费）。
     */
    public function consumptionJson(): JsonResponse
    {
        $refExpr = "COALESCE(a.ref_group, CONCAT('op:', a.operation_id))";

        $rows = DB::table('kassie_calendar_pap_adjustments as a')
            ->leftJoin('calendar_operations as o', 'o.id', '=', 'a.operation_id')
            ->where('a.source', '<>', 'attendance_audit')
            ->selectRaw("$refExpr as ref_key")
            ->selectRaw('MIN(a.source) as merchant')
            ->selectRaw('MIN(o.title) as op_title')
            ->selectRaw('COUNT(DISTINCT a.character_id) as participants')
            ->selectRaw('SUM(CASE WHEN a.value < 0 THEN -a.value ELSE 0 END) as debited')
            ->selectRaw('SUM(CASE WHEN a.value > 0 THEN a.value ELSE 0 END) as refunded')
            ->selectRaw('SUM(-a.value) as net_consumed')
            ->selectRaw('MAX(a.created_at) as last_at')
            ->groupByRaw($refExpr)
            // 聚合别名不能进 HAVING/ORDER BY，写完整表达式
            ->havingRaw('SUM(-a.value) <> 0')
            ->orderByRaw('MAX(a.created_at) DESC')
            ->get();

        return response()->json([
            'data' => $rows->map(fn($r) => [
                'ref_key' => $r->ref_key,
                'merchant' => $r->merchant,
                'title' => $r->op_title,
                'participants' => (int) $r->participants,
                'debited' => (float) $r->debited,
                'refunded' => (float) $r->refunded,
                'net_consumed' => (float) $r->net_consumed,
                'last_at' => $r->last_at,
            ])->values(),
        ]);
    }

    /**
     * 单场次逐笔流水：?ref=<ref_group> 或 ?ref=op:<operation_id>（历史无 ref_group 的回退键）。
     */
    public function consumptionDetailJson(Request $request): JsonResponse
    {
        $ref = (string) $request->query('ref', '');
        if ($ref === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing ref.'], 400);
        }

        $query = DB::table('kassie_calendar_pap_adjustments as a')
            ->where('a.source', '<>', 'attendance_audit');

        if (str_starts_with($ref, 'op:')) {
            $query->whereNull('a.ref_group')->where('a.operation_id', (int) substr($ref, 3));
        } else {
            $query->where('a.ref_group', $ref);
        }

        $items = $query->orderBy('a.created_at')
            ->get(['a.character_id', 'a.value', 'a.source', 'a.reason', 'a.external_ref', 'a.created_at']);

        $names = CharacterInfo::whereIn('character_id', $items->pluck('character_id'))
            ->pluck('name', 'character_id');

        return response()->json([
            'status' => 'success',
            'items' => $items->map(fn($a) => [
                'character_id' => $a->character_id,
                'character_name' => $names->get($a->character_id, '#' . $a->character_id),
                'value' => (float) $a->value,
                'source' => $a->source,
                'reason' => $a->reason,
                'external_ref' => $a->external_ref,
                'at' => $a->created_at,
            ])->values(),
        ]);
    }
}
