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
 * 消费 operation 通过 calendar_operations.is_consumption(o) 识别：
 *   出勤  = SUM(普通行动 value)            o.is_consumption = 0
 *   消费  = SUM(-消费行动 value)           o.is_consumption = 1
 *   可用  = SUM(全部 value)                出勤 - 消费 恒等
 *
 * 用到 ATTENDANCE/CONSUMED_EXPR 的查询必须 join calendar_operations as o。
 *
 * @package Seat\Kassie\Calendar\Http\Controllers
 */
class CorporationController extends Controller
{
    // 带符号三口径表达式（用于 SELECT / ORDER BY；MySQL 不允许 ORDER BY 引用聚合别名）
    private const ATTENDANCE_EXPR = 'SUM(CASE WHEN o.is_consumption = 0 THEN kassie_calendar_paps.value ELSE 0 END)';
    private const CONSUMED_EXPR = 'SUM(CASE WHEN o.is_consumption = 1 THEN -kassie_calendar_paps.value ELSE 0 END)';
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
            ->join('calendar_operations as o', 'o.id', '=', 'kassie_calendar_paps.operation_id')
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
            ->join('calendar_operations as o', 'o.id', '=', 'kassie_calendar_paps.operation_id')
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
        // 类型分布只反映「出勤 PAP 的构成」，消费是消耗、与收入不同层级，排除消费 operation 不入饼图
        $query = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->join('calendar_tag_operation as cto', 'cto.operation_id', '=', 'kassie_calendar_paps.operation_id')
            ->join('calendar_tags as ct', 'ct.id', '=', 'cto.tag_id')
            ->join('calendar_operations as o', 'o.id', '=', 'kassie_calendar_paps.operation_id')
            ->where('o.is_consumption', 0)
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

    /**
     * 军团消费 PAP（抽奖等消耗）按所选时间范围汇总。
     * 返回当前净消费总额 + 按抽奖明细（已退款净额为 0 的不列出）。
     */
    public function getConsumedJson(int $corporation_id): JsonResponse
    {
        $year = (int)(request()->query('year') ?? carbon()->year);
        $month = request()->query('month') ? (int)request()->query('month') : null;
        $startDate = Pap::statisticsStartDate();

        $query = DB::table('kassie_calendar_paps as p')
            ->join('character_affiliations as ca', 'p.character_id', '=', 'ca.character_id')
            ->join('calendar_operations as o', 'o.id', '=', 'p.operation_id')
            ->where('o.is_consumption', 1)
            ->where('ca.corporation_id', $corporation_id)
            ->where('p.year', $year)
            ->where('p.join_time', '>=', $startDate);

        if ($month !== null) {
            $query->where('p.month', $month);
        }

        // 按消费锚 operation 聚合（每商户每月一行；历史抽奖各自独立 operation 保留逐个明细）。
        // 逐「场次」(ref_group) 的更细明细见外部消费审查页。
        $items = $query
            ->groupBy('o.id', 'o.title')
            ->selectRaw('o.title')
            ->selectRaw('SUM(-p.value) as consumed')
            ->havingRaw('SUM(-p.value) <> 0')
            ->orderByRaw('SUM(-p.value) DESC')
            ->get()
            ->map(fn($item) => [
                'title' => $item->title,
                'consumed' => (float) $item->consumed,
            ]);

        return response()->json([
            'total' => (float) $items->sum('consumed'),
            'items' => $items->values(),
        ]);
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

        // 累计可用余额：各主角色名下全部关联角色、起始日以来 SUM(value)（不分区间，跨军团聚合，
        // 即真正可继续消费的余额，与 API / 抽奖口径一致）。仅用于导出列。
        $balances = DB::table('kassie_calendar_paps')
            ->leftJoin('refresh_tokens as rt', 'kassie_calendar_paps.character_id', '=', 'rt.character_id')
            ->leftJoin('users as u', 'rt.user_id', '=', 'u.id')
            ->where('kassie_calendar_paps.join_time', '>=', $startDate)
            ->groupBy(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id)'))
            ->select(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id) as cid'))
            ->selectRaw('SUM(kassie_calendar_paps.value) as balance')
            ->pluck('balance', 'cid');

        return response()->json($ranking->map(fn($item) => [
            'character_id' => $item->character_id,
            'name' => $item->character?->name ?? trans('web::seat.unknown'),
            'attendance_pap' => (float) $item->attendance_pap,
            'consumed_pap' => (float) $item->consumed_pap,
            'available_pap' => (float) $item->available_pap,
            'available_balance' => (float) ($balances[$item->character_id] ?? 0),
            'qty' => (float) $item->qty,
        ])->values());
    }
}
