<?php
/**
 * User: Warlof Tutsimo <loic.leuilliot@gmail.com>
 * Date: 03/01/2018
 * Time: 11:08
 */

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Kassie\Calendar\Models\Pap;
use Seat\Web\Http\Controllers\Controller;

/**
 * Class CorporationController.
 *
 * 阶段 7：军团 PAP 统计统一出勤 / 消费 / 当前可用三口径（带符号定义，见计划 §11），
 * 并以全局 PAP 起始日（Pap::statisticsStartDate()）为统计起点。
 *
 * 抽奖 operation 通过 LEFT JOIN kassie_calendar_lotteries(l) 识别：
 *   出勤  = SUM(普通行动 value)            l.id IS NULL
 *   消费  = SUM(-抽奖行动 value)           l.id IS NOT NULL
 *   可用  = SUM(全部 value)                出勤 - 消费 恒等
 *
 * @package Seat\Kassie\Calendar\Http\Controllers
 */
class CorporationController extends Controller
{
    // 带符号三口径表达式（用于 SELECT / ORDER BY；MySQL 不允许 ORDER BY 引用聚合别名）
    private const ATTENDANCE_EXPR = 'SUM(CASE WHEN l.id IS NULL THEN kassie_calendar_paps.value ELSE 0 END)';
    private const CONSUMED_EXPR = 'SUM(CASE WHEN l.id IS NOT NULL THEN -kassie_calendar_paps.value ELSE 0 END)';
    private const AVAILABLE_EXPR = 'SUM(kassie_calendar_paps.value)';

    /**
     * @param CorporationInfo $corporation
     *
     * @return Factory|View
     */
    public function getPaps(CorporationInfo $corporation): Factory|View
    {
        return view('calendar::corporation.paps', [
            'corporation' => $corporation,
        ]);
    }

    public function getMonthlyTrendJson(int $corporation_id): JsonResponse
    {
        $year = (int)(request()->query('year') ?? carbon()->year);
        $startDate = Pap::statisticsStartDate();

        $trend = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->leftJoin('kassie_calendar_lotteries as l', 'l.operation_id', '=', 'kassie_calendar_paps.operation_id')
            ->where('ca.corporation_id', $corporation_id)
            ->where('kassie_calendar_paps.year', $year)
            ->where('kassie_calendar_paps.join_time', '>=', $startDate)
            ->groupBy('kassie_calendar_paps.month')
            ->orderBy('kassie_calendar_paps.month')
            ->selectRaw('kassie_calendar_paps.month')
            ->selectRaw('COALESCE(' . self::ATTENDANCE_EXPR . ', 0) as attendance')
            ->selectRaw('COALESCE(' . self::CONSUMED_EXPR . ', 0) as consumed')
            ->selectRaw('COALESCE(' . self::AVAILABLE_EXPR . ', 0) as available')
            // qty 兼容旧前端，默认等于出勤 PAP（趋势图主线）
            ->selectRaw('COALESCE(' . self::ATTENDANCE_EXPR . ', 0) as qty')
            ->get();

        return response()->json($trend);
    }

    private function getGroupedRanking(int $corporationId, \Carbon\Carbon $startDate, array $conditions): Collection
    {
        $query = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->leftJoin('refresh_tokens as rt', 'kassie_calendar_paps.character_id', '=', 'rt.character_id')
            ->leftJoin('users as u', 'rt.user_id', '=', 'u.id')
            ->leftJoin('kassie_calendar_lotteries as l', 'l.operation_id', '=', 'kassie_calendar_paps.operation_id')
            ->where('ca.corporation_id', $corporationId)
            ->where('kassie_calendar_paps.join_time', '>=', $startDate);

        foreach ($conditions as [$column, $value]) {
            $query->where("kassie_calendar_paps.{$column}", $value);
        }

        $ranking = $query
            ->select(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id) as character_id'))
            ->selectRaw('COALESCE(' . self::ATTENDANCE_EXPR . ', 0) as attendance_pap')
            ->selectRaw('COALESCE(' . self::CONSUMED_EXPR . ', 0) as consumed_pap')
            ->selectRaw('COALESCE(' . self::AVAILABLE_EXPR . ', 0) as available_pap')
            // qty 兼容旧前端，保留为当前可用 PAP
            ->selectRaw('COALESCE(' . self::AVAILABLE_EXPR . ', 0) as qty')
            ->groupBy(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id)'))
            // 默认按出勤 PAP 排，再按当前可用 PAP；聚合别名不能进 ORDER BY，写完整表达式
            ->orderByRaw(self::ATTENDANCE_EXPR . ' DESC')
            ->orderByRaw(self::AVAILABLE_EXPR . ' DESC')
            ->get();

        return $this->attachCharacterInfo($ranking);
    }

    private function getTypeDistribution(int $corporationId, \Carbon\Carbon $startDate, int $year, ?int $month = null): Collection
    {
        $query = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->join('calendar_tag_operation as cto', 'cto.operation_id', '=', 'kassie_calendar_paps.operation_id')
            ->join('calendar_tags as ct', 'ct.id', '=', 'cto.tag_id')
            ->where('ca.corporation_id', $corporationId)
            ->where('kassie_calendar_paps.year', $year)
            ->where('kassie_calendar_paps.join_time', '>=', $startDate);

        if ($month !== null) {
            $query->where('kassie_calendar_paps.month', $month);
        }

        return $query
            ->select('ct.analytics', DB::raw('MIN(ct.bg_color) as bg_color'), DB::raw('SUM(kassie_calendar_paps.value) as qty'))
            ->groupBy('ct.analytics')
            ->orderBy('qty', 'desc')
            ->get();
    }

    private function attachCharacterInfo(Collection $ranking): Collection
    {
        $characters = CharacterInfo::whereIn('character_id', $ranking->pluck('character_id'))
            ->get()
            ->keyBy('character_id');

        return $ranking->map(function ($item) use ($characters) {
            $item->character = $characters->get($item->character_id);
            return $item;
        });
    }

    public function getTypeDistributionJson(int $corporation_id): JsonResponse
    {
        $year = (int)(request()->query('year') ?? carbon()->year);
        $month = request()->query('month') ? (int)request()->query('month') : null;
        $startDate = Pap::statisticsStartDate();

        $data = $this->getTypeDistribution($corporation_id, $startDate, $year, $month)
            ->map(function ($item) {
                $key = 'calendar::seat.' . $item->analytics;
                $translated = trans($key);
                $item->analytics = $translated !== $key ? $translated : $item->analytics;
                return $item;
            });

        return response()->json($data);
    }

    public function getRankingJson(int $corporation_id): JsonResponse
    {
        $year = (int)(request()->query('year') ?? carbon()->year);
        $month = request()->query('month') ? (int)request()->query('month') : null;
        $startDate = Pap::statisticsStartDate();

        if ($month) {
            $ranking = $this->getGroupedRanking($corporation_id, $startDate, [
                ['month', $month],
                ['year', $year],
            ]);
        } else {
            $ranking = $this->getGroupedRanking($corporation_id, $startDate, [
                ['year', $year],
            ]);
        }

        return response()->json($ranking->map(fn($item) => [
            'character_id' => $item->character_id,
            'name' => $item->character?->name ?? trans('web::seat.unknown'),
            'attendance_pap' => (float) $item->attendance_pap,
            'consumed_pap' => (float) $item->consumed_pap,
            'available_pap' => (float) $item->available_pap,
            'qty' => (float) $item->qty,
        ])->values());
    }
}
