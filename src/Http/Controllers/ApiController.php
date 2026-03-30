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
    public function getCharacterPaps(int $character_id, Request $request): JsonResponse
    {
        $since = $this->parseSince($request);
        $result = $this->resolvePap($character_id, $since);

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

        $since = $this->parseSince($request);
        $data = [];
        $notFound = [];

        foreach ($ids as $id) {
            $result = $this->resolvePap($id, $since);
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
     * 解析 since 参数，格式 YYYY-MM-DD，缺省返回 2026-01-01
     */
    private function parseSince(Request $request): \Carbon\Carbon
    {
        $since = $request->query('since');

        if ($since) {
            try {
                return carbon($since)->startOfDay();
            } catch (\Exception) {
                // 格式无效时回退到默认起始日期
            }
        }

        return carbon('2026-01-01');
    }

    /**
     * 解析单个角色的主角色聚合 PAP，返回 null 表示角色未找到
     */
    private function resolvePap(int $characterId, \Carbon\Carbon $since): ?array
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
            ->where('join_time', '>=', $since)
            ->sum('value');

        return [
            'character_id' => $mainCharacterId,
            'user_id' => $user->id,
            'total_pap' => (float) $totalPap,
            'since' => $since->toDateString(),
        ];
    }
}
