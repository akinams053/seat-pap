<?php
/**
 * User: Warlof Tutsimo <loic.leuilliot@gmail.com>
 * Date: 21/12/2017
 * Time: 14:24
 */

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Kassie\Calendar\Models\Pap;
use Seat\Web\Http\Controllers\Controller;

/**
 * Class CharacterController.
 *
 * @package Seat\Kassie\Calendar\Http\Controllers
 */
class CharacterController extends Controller
{
    public function paps(CharacterInfo $character): Factory|View
    {
        $today = carbon();
        $startDate = Pap::statisticsStartDate();

        // 主角色聚合：获取该角色所属用户的所有角色 ID
        $characterIds = $this->getAssociatedCharacterIds($character);
        $mainCharacterId = $this->getMainCharacterId($character);

        // 月度趋势：每月给出出勤 / 消费 / 当前可用三口径（图表默认画出勤 PAP）
        $monthlyPaps = DB::table('kassie_calendar_paps as p')
            ->join('calendar_operations as o', 'o.id', '=', 'p.operation_id')
            ->whereIn('p.character_id', $characterIds)
            ->where('p.join_time', '>=', $startDate)
            ->groupBy('p.year', 'p.month')
            ->orderBy('p.year')
            ->orderBy('p.month')
            ->selectRaw('p.year, p.month')
            ->selectRaw('COALESCE(SUM(CASE WHEN o.is_consumption = 0 THEN p.value ELSE 0 END), 0) as attendance')
            ->selectRaw('COALESCE(SUM(CASE WHEN o.is_consumption = 1 THEN -p.value ELSE 0 END), 0) as consumed')
            ->selectRaw('COALESCE(SUM(p.value), 0) as available')
            ->get();

        // 当月 / 当年 三口径 breakdown
        $thisMonth = $this->papBreakdown($characterIds, $startDate, [
            ['month', $today->month],
            ['year', $today->year],
        ]);
        $thisYear = $this->papBreakdown($characterIds, $startDate, [
            ['year', $today->year],
        ]);

        // 累计可用 PAP：起始日以来全部关联角色 SUM(value)，即真正可继续消费的余额（不分区间）
        $availableBalance = (float) DB::table('kassie_calendar_paps')
            ->whereIn('character_id', $characterIds)
            ->where('join_time', '>=', $startDate)
            ->sum('value');

        // 荣誉榜按主角色聚合，按出勤 PAP 排（取消本周榜，只保留本月 / 本年）
        $monthlyRanking = $this->getGlobalGroupedRanking($startDate, [
            ['month', $today->month],
            ['year', $today->year],
        ]);

        $yearlyRanking = $this->getGlobalGroupedRanking($startDate, [
            ['year', $today->year],
        ]);

        return view('calendar::character.paps', [
            'monthlyPaps' => $monthlyPaps,
            'thisMonth' => $thisMonth,
            'thisYear' => $thisYear,
            'availableBalance' => $availableBalance,
            'monthlyRanking' => $monthlyRanking,
            'yearlyRanking' => $yearlyRanking,
            'character' => $character,
            'mainCharacterId' => $mainCharacterId,
        ]);
    }

    /**
     * 出勤 / 消费 / 当前可用三口径汇总（带符号定义，见计划 §11）
     *
     * 出勤 = 普通行动全部最终值（含被扣成负数的惩罚）
     * 消费 = 抽奖行动全部最终值取负
     * 可用 = 全部最终值求和；恒等式 出勤 - 消费 = 可用 精确成立
     */
    private function papBreakdown(array $characterIds, \Carbon\Carbon $startDate, array $conditions): array
    {
        $query = DB::table('kassie_calendar_paps as p')
            ->join('calendar_operations as o', 'o.id', '=', 'p.operation_id')
            ->whereIn('p.character_id', $characterIds)
            ->where('p.join_time', '>=', $startDate);

        foreach ($conditions as [$column, $value]) {
            $query->where("p.{$column}", $value);
        }

        $row = $query
            ->selectRaw('COALESCE(SUM(CASE WHEN o.is_consumption = 0 THEN p.value ELSE 0 END), 0) as attendance')
            ->selectRaw('COALESCE(SUM(CASE WHEN o.is_consumption = 1 THEN -p.value ELSE 0 END), 0) as consumed')
            ->selectRaw('COALESCE(SUM(p.value), 0) as available')
            ->first();

        return [
            'attendance' => (float) $row->attendance,
            'consumed' => (float) $row->consumed,
            'available' => (float) $row->available,
        ];
    }

    private function getAssociatedCharacterIds(CharacterInfo $character): array
    {
        $token = RefreshToken::find($character->character_id);
        if ($token?->user) {
            return $token->user->associatedCharacterIds();
        }
        return [$character->character_id];
    }

    private function getMainCharacterId(CharacterInfo $character): int
    {
        $token = RefreshToken::find($character->character_id);
        return $token?->user?->main_character_id ?? $character->character_id;
    }

    private function getGlobalGroupedRanking(\Carbon\Carbon $startDate, array $conditions): Collection
    {
        // 荣誉榜按出勤 PAP 排（普通行动最终值，排除消费）；聚合别名不能进 ORDER BY，写完整表达式
        $attendanceExpr = 'SUM(CASE WHEN o.is_consumption = 0 THEN kassie_calendar_paps.value ELSE 0 END)';

        $query = DB::table('kassie_calendar_paps')
            ->leftJoin('refresh_tokens as rt', 'kassie_calendar_paps.character_id', '=', 'rt.character_id')
            ->leftJoin('users as u', 'rt.user_id', '=', 'u.id')
            ->join('calendar_operations as o', 'o.id', '=', 'kassie_calendar_paps.operation_id')
            ->where('kassie_calendar_paps.join_time', '>=', $startDate);

        foreach ($conditions as [$column, $value]) {
            $query->where("kassie_calendar_paps.{$column}", $value);
        }

        $ranking = $query
            ->select(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id) as character_id'))
            ->selectRaw('COALESCE(' . $attendanceExpr . ', 0) as qty')
            ->groupBy(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id)'))
            ->orderByRaw($attendanceExpr . ' DESC')
            ->get();

        $characters = CharacterInfo::whereIn('character_id', $ranking->pluck('character_id'))
            ->get()
            ->keyBy('character_id');

        return $ranking->map(function ($item) use ($characters) {
            $item->character = $characters->get($item->character_id);
            return $item;
        });
    }
}
