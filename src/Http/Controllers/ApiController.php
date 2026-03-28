<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\RefreshToken;

class ApiController
{
    /**
     * 单角色查询：GET /api/calendar/paps/{character_id}
     */
    public function getCharacterPaps(int $character_id): JsonResponse
    {
        $result = $this->resolvePap($character_id);

        if ($result === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Character not found or not linked to a SeAT user.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            ...$result,
            'sync_at' => carbon()->toDateTimeString(),
        ]);
    }

    /**
     * 批量查询：GET /api/calendar/paps?characters=id1,id2,id3
     */
    public function getBatchPaps(Request $request): JsonResponse
    {
        $input = $request->query('characters', '');

        if (!$input) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing "characters" parameter.',
            ], 400);
        }

        $ids = array_unique(array_filter(array_map('intval', explode(',', $input))));

        if (count($ids) > 200) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many characters. Maximum 200 per request.',
            ], 400);
        }

        $data = [];
        $notFound = [];

        foreach ($ids as $id) {
            $result = $this->resolvePap($id);
            if ($result !== null) {
                $data[] = $result;
            } else {
                $notFound[] = $id;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'not_found' => $notFound,
            'sync_at' => carbon()->toDateTimeString(),
        ]);
    }

    /**
     * 解析单个角色的主角色聚合 PAP，返回 null 表示角色未找到
     */
    private function resolvePap(int $characterId): ?array
    {
        $token = RefreshToken::find($characterId);
        $user = $token?->user;

        if (!$user) {
            return null;
        }

        $mainCharacterId = $user->main_character_id ?? $characterId;
        $characterIds = $user->associatedCharacterIds();

        $totalPap = DB::table('kassie_calendar_paps')
            ->whereIn('character_id', $characterIds)
            ->where('year', '>=', 2026)
            ->sum('value');

        return [
            'character_id' => $mainCharacterId,
            'user_id' => $user->id,
            'total_pap' => (float) $totalPap,
        ];
    }
}
