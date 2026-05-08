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
use Seat\Kassie\Calendar\Http\Controllers\Concerns\ValidatesPapAccess;
use Seat\Kassie\Calendar\Models\Operation;
use Seat\Kassie\Calendar\Models\Pap;
use Seat\Kassie\Calendar\Models\PapAdjustment;
use Seat\Web\Http\Controllers\Controller;

class AuditController extends Controller
{
    use ValidatesPapAccess;

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
        $associated = auth()->user()->associatedCharacterIds();

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
            ->groupBy('o.id', 'o.title', 'o.fc', 'o.fc_character_id', 'o.end_at');

        $totalCount = DB::table('calendar_operations as o')
            ->join('kassie_calendar_paps as p', 'p.operation_id', '=', 'o.id')
            ->distinct()
            ->count('o.id');

        $rows = $base
            ->orderByRaw('MAX(p.created_at) IS NULL, MAX(p.created_at) DESC')
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
            'is_fleet_commander' => $r->fc_character_id !== null && in_array($r->fc_character_id, $associated),
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

        $isFc = $operation->fc_character_id !== null
            && in_array($operation->fc_character_id, auth()->user()->associatedCharacterIds());

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
            'is_fleet_commander' => $isFc,
            'members' => $members,
        ]);
    }

    /**
     * 写入奖惩记录并回写 paps.value
     */
    public function adjust(Request $request, int $operationId): JsonResponse
    {
        $check = $this->validatePapAccess($operationId);
        if ($check instanceof JsonResponse) return $check;

        ['operation' => $operation] = $check;

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

        $adjustment = PapAdjustment::create([
            'operation_id' => $operationId,
            'character_id' => $validated['character_id'],
            'value' => $signed,
            'reason' => $validated['reason'],
            'created_by_character_id' => $operation->fc_character_id,
            'created_at' => carbon(),
        ]);

        Pap::recomputeValueFor($operationId, (int) $validated['character_id']);

        $newValue = (float) DB::table('kassie_calendar_paps')
            ->where('operation_id', $operationId)
            ->where('character_id', $validated['character_id'])
            ->value('value');

        $byName = CharacterInfo::find($operation->fc_character_id)?->name ?? '—';

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
}
