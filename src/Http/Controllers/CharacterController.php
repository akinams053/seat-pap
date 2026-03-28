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

        // 主角色聚合：获取该角色所属用户的所有角色 ID
        $characterIds = $this->getAssociatedCharacterIds($character);
        $mainCharacterId = $this->getMainCharacterId($character);

        $monthlyPaps = Pap::whereIn('character_id', $characterIds)
            ->select('year', 'month', DB::raw('sum(value) as qty'))
            ->groupBy('year', 'month')
            ->get();

        // 当月/当年 PAP 汇总
        $thisMonthPaps = Pap::whereIn('character_id', $characterIds)
            ->where('month', $today->month)
            ->where('year', $today->year)
            ->sum('value');

        $thisYearPaps = Pap::whereIn('character_id', $characterIds)
            ->where('year', $today->year)
            ->sum('value');

        // 排名按主角色聚合
        $weeklyRanking = $this->getGlobalGroupedRanking([
            ['week', $today->weekOfMonth],
            ['month', $today->month],
            ['year', $today->year],
        ]);

        $monthlyRanking = $this->getGlobalGroupedRanking([
            ['month', $today->month],
            ['year', $today->year],
        ]);

        $yearlyRanking = $this->getGlobalGroupedRanking([
            ['year', $today->year],
        ]);

        return view('calendar::character.paps', [
            'monthlyPaps' => $monthlyPaps,
            'thisMonthPaps' => $thisMonthPaps,
            'thisYearPaps' => $thisYearPaps,
            'weeklyRanking' => $weeklyRanking,
            'monthlyRanking' => $monthlyRanking,
            'yearlyRanking' => $yearlyRanking,
            'character' => $character,
            'mainCharacterId' => $mainCharacterId,
        ]);
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

    private function getGlobalGroupedRanking(array $conditions): Collection
    {
        $query = DB::table('kassie_calendar_paps')
            ->leftJoin('refresh_tokens as rt', 'kassie_calendar_paps.character_id', '=', 'rt.character_id')
            ->leftJoin('users as u', 'rt.user_id', '=', 'u.id');

        foreach ($conditions as [$column, $value]) {
            $query->where("kassie_calendar_paps.{$column}", $value);
        }

        $ranking = $query
            ->select(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id) as character_id'))
            ->selectRaw('SUM(kassie_calendar_paps.value) as qty')
            ->groupBy(DB::raw('COALESCE(u.main_character_id, kassie_calendar_paps.character_id)'))
            ->orderBy('qty', 'desc')
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
