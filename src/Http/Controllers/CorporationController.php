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
        return view('calendar::corporation.paps', [
            'corporation' => $corporation,
        ]);
    }

    public function getMonthlyTrendJson(int $corporation_id): JsonResponse
    {
        $year = (int)(request()->query('year') ?? carbon()->year);

        $trend = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->where('ca.corporation_id', $corporation_id)
            ->where('kassie_calendar_paps.year', $year)
            ->select('kassie_calendar_paps.month', DB::raw('SUM(kassie_calendar_paps.value) as qty'))
            ->groupBy('kassie_calendar_paps.month')
            ->orderBy('kassie_calendar_paps.month')
            ->get();

        return response()->json($trend);
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

    private function getTypeDistribution(int $corporationId, int $year, ?int $month = null): Collection
    {
        $query = DB::table('kassie_calendar_paps')
            ->join('character_affiliations as ca', 'kassie_calendar_paps.character_id', '=', 'ca.character_id')
            ->join('calendar_tag_operation as cto', 'cto.operation_id', '=', 'kassie_calendar_paps.operation_id')
            ->join('calendar_tags as ct', 'ct.id', '=', 'cto.tag_id')
            ->where('ca.corporation_id', $corporationId)
            ->where('kassie_calendar_paps.year', $year);

        if ($month !== null) {
            $query->where('kassie_calendar_paps.month', $month);
        }

        return $query
            ->select('ct.analytics', 'ct.bg_color', DB::raw('SUM(kassie_calendar_paps.value) as qty'))
            ->groupBy('ct.analytics', 'ct.bg_color')
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

        $data = $this->getTypeDistribution($corporation_id, $year, $month)
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

        if ($month) {
            $ranking = $this->getGroupedRanking($corporation_id, [
                ['month', $month],
                ['year', $year],
            ]);
        } else {
            $ranking = $this->getGroupedRanking($corporation_id, [
                ['year', $year],
            ]);
        }

        return response()->json($ranking->map(fn($item) => [
            'character_id' => $item->character_id,
            'name' => $item->character?->name ?? trans('web::seat.unknown'),
            'qty' => $item->qty,
        ])->values());
    }
}
