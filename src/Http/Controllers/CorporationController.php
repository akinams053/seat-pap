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
 * @package Seat\Kassie\Calendar\Http\Controllers
 */
class CorporationController extends Controller
{
    /**
     * @param CorporationInfo $corporation
     *
     * @return Factory|View
     */
    public function getPaps(CorporationInfo $corporation): Factory|View
    {
        $today = carbon();
        $corpId = $corporation->corporation_id;

        $weeklyRanking = $this->getGroupedRanking($corpId, [
            ['week', $today->weekOfMonth],
            ['month', $today->month],
            ['year', $today->year],
        ]);

        $monthlyRanking = $this->getGroupedRanking($corpId, [
            ['month', $today->month],
            ['year', $today->year],
        ]);

        $yearlyRanking = $this->getGroupedRanking($corpId, [
            ['year', $today->year],
        ]);

        // 月度趋势
        $monthlyTrend = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->where('ca.corporation_id', $corpId)
            ->where('kassie_calendar_paps.year', $today->year)
            ->select('kassie_calendar_paps.month', DB::raw('SUM(kassie_calendar_paps.value) as qty'))
            ->groupBy('kassie_calendar_paps.month')
            ->orderBy('kassie_calendar_paps.month')
            ->get();

        // 按类型分布
        $typeDistribution = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->join('calendar_tag_operation as cto', 'cto.operation_id', '=', 'kassie_calendar_paps.operation_id')
            ->join('calendar_tags as ct', 'ct.id', '=', 'cto.tag_id')
            ->where('ca.corporation_id', $corpId)
            ->where('kassie_calendar_paps.year', $today->year)
            ->select('ct.analytics', 'ct.bg_color', DB::raw('SUM(kassie_calendar_paps.value) as qty'))
            ->groupBy('ct.analytics', 'ct.bg_color')
            ->orderBy('qty', 'desc')
            ->get();

        return view('calendar::corporation.paps', [
            'weeklyRanking' => $weeklyRanking,
            'monthlyRanking' => $monthlyRanking,
            'yearlyRanking' => $yearlyRanking,
            'monthlyTrend' => $monthlyTrend,
            'typeDistribution' => $typeDistribution,
            'corporation' => $corporation,
        ]);
    }

    private function getGroupedRanking(int $corporationId, array $conditions): Collection
    {
        $query = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->leftJoin('refresh_tokens as rt', 'kassie_calendar_paps.character_id', '=', 'rt.character_id')
            ->leftJoin('users as u', 'rt.user_id', '=', 'u.id')
            ->where('ca.corporation_id', $corporationId);

        foreach ($conditions as [$column, $value]) {
            $query->where("kassie_calendar_paps.{$column}", $value);
        }

        $ranking = $query
            ->select(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id) as character_id'))
            ->selectRaw('SUM(kassie_calendar_paps.value) as qty')
            ->groupBy(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id)'))
            ->orderBy('qty', 'desc')
            ->get();

        return $this->attachCharacterInfo($ranking);
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

    /**
     * @param int $corporation_id
     * @return JsonResponse
     */
    public function getYearPapsStats(int $corporation_id): JsonResponse
    {
        $year = request()->query('year');
        $grouped = request()->query('grouped');

        if (is_null($year))
            $year = carbon()->year;

        if (is_null($grouped))
            $grouped = false;

        if (!$grouped)
            return response()->json(
                Pap::with('character', 'character.affiliation')
                    ->whereHas('character.affiliation', function ($query) use ($corporation_id): void {
                        $query->where('corporation_id', $corporation_id);
                    })
                    ->where('year', intval($year))
                    ->select('character_id')
                    ->selectRaw('SUM(value) as qty')
                    ->groupBy('character_id')
                    ->orderBy('qty', 'desc')
                    ->get()
                    ->map(fn($pap): array => [
                        'character_id' => $pap->character_id,
                        'name' => $pap->character->name,
                        'qty' => $pap->qty,
                    ])
                    ->sortBy('name')
                    ->values());

        return response()->json(
            Pap::with('character', 'character.affiliation', 'character.user')
                ->whereHas('character.affiliation', function ($query) use ($corporation_id): void {
                    $query->where('corporation_id', $corporation_id);
                })
                ->where('year', (int)$year)
                ->select('character_id')
                ->selectRaw(DB::raw('SUM(value) as qty'))
                ->groupBy('character_id')
                ->orderBy('qty', 'desc')
                ->get()
                ->groupBy('character.user.id')
                ->map(function ($user): array {
                    $pap = [
                        'character_id' => 0,
                        'name' => trans('web::seat.unknown'),
                        'qty' => 0,
                    ];

                    foreach ($user as $character) {
                        $pap['character_id'] = $character->user->main_character_id;
                        $pap['name'] = $character->user->name;
                        $pap['qty'] += $character->qty;
                    }

                    return $pap;
                })
                ->sortBy('name')
                ->values());
    }

    /**
     * @param int $corporation_id
     * @return JsonResponse
     */
    public function getMonthlyStackedPapsStats(int $corporation_id): JsonResponse
    {
        $year = is_null(request()->query('year')) ? carbon()->year : (int)(request()->query('year'));
        $month = is_null(request()->query('month')) ? carbon()->month : (int)(request()->query('month'));
        $grouped = request()->query('grouped') ?: false;

        $paps = Pap::select('ci.character_id', 'cto.operation_id', 'analytics', 'value')
            ->join('character_infos as ci', 'kassie_calendar_paps.character_id', 'ci.character_id')
            ->join('character_affiliations as ca', 'ci.character_id', 'ca.character_id')
            ->join('calendar_tag_operation as cto', 'cto.operation_id', 'kassie_calendar_paps.operation_id')
            ->join('calendar_tags as ct', 'ct.id', 'cto.tag_id')
            ->where('year', $year)
            ->where('month', $month)
            ->where('corporation_id', $corporation_id);

        if ($grouped) {
            return response()->json(
                DB::table(DB::raw("({$paps->toSql()}) as paps"))
                    ->join('refresh_tokens as rt', 'paps.character_id', 'rt.character_id')
                    ->join('users as u', 'rt.user_id', 'u.id')
                    ->mergeBindings($paps->getQuery())
                    ->select('analytics', 'main_character_id as character_id', 'name')
                    ->selectRaw('SUM(value) as qty')
                    ->groupBy('analytics', 'main_character_id', 'name')
                    ->orderBy('qty', 'desc')
                    ->orderBy('name', 'asc')
                    ->get()
            );
        }

        return response()->json(
            DB::table(DB::raw("({$paps->addSelect('ci.name')->toSql()}) as paps"))
                ->mergeBindings($paps->getQuery())
                ->select('analytics', 'character_id', 'name')
                ->selectRaw('SUM(value) as qty')
                ->groupBy('analytics', 'character_id', 'name')
                ->orderBy('qty', 'desc')
                ->orderBy('name', 'asc')
                ->get());
    }
}
