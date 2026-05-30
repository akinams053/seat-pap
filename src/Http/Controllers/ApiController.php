<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Kassie\Calendar\Models\Pap;

class ApiController
{
    /**
     * 单角色查询：GET /api/calendar/paps/{character_id}
     */
    public function getCharacterPaps(int $character_id, Request $request): JsonResponse
    {
        $since = $this->parseSince($request);
        $result = $this->resolvePap($character_id, $since, $request->boolean('breakdown'));

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
        $breakdown = $request->boolean('breakdown');
        $data = [];
        $notFound = [];

        foreach ($ids as $id) {
            $result = $this->resolvePap($id, $since, $breakdown);
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
     * 解析 since 参数，格式 YYYY-MM-DD。
     *
     * 缺省回退全局 PAP 起始日；外部传入的 since 会被 clamp 到起始日下限，
     * 即实际使用 max(since, 起始日)——外部商店永远查不到起始日之前的 PAP（计划 §12.6）。
     */
    private function parseSince(Request $request): \Carbon\Carbon
    {
        $start = Pap::statisticsStartDate();
        $since = $request->query('since');

        if ($since) {
            try {
                $parsed = carbon($since)->startOfDay();

                return $parsed->lessThan($start) ? $start : $parsed;
            } catch (\Exception) {
                // 格式无效时回退到全局起始日
            }
        }

        return $start;
    }

    /**
     * 解析单个角色的主角色聚合 PAP，返回 null 表示角色未找到
     *
     * $breakdown=true 时附带出勤 / 消费 / 当前可用三口径（计划 §11）；
     * 默认响应保持 total_pap 兼容旧语义不变。
     */
    private function resolvePap(int $characterId, \Carbon\Carbon $since, bool $breakdown = false): ?array
    {
        $token = RefreshToken::find($characterId);
        $user = $token?->user;

        if (!$user) {
            return null;
        }

        $mainCharacterId = $user->main_character_id ?? $characterId;
        $characterIds = $user->associatedCharacterIds();

        // 抽奖 operation 通过 LEFT JOIN lotteries 识别，带符号三口径，恒等：出勤 - 消费 = 可用
        $row = DB::table('kassie_calendar_paps as p')
            ->leftJoin('kassie_calendar_lotteries as l', 'l.operation_id', '=', 'p.operation_id')
            ->whereIn('p.character_id', $characterIds)
            ->where('p.join_time', '>=', $since)
            ->selectRaw('COALESCE(SUM(CASE WHEN l.id IS NULL THEN p.value ELSE 0 END), 0) as attendance')
            ->selectRaw('COALESCE(SUM(CASE WHEN l.id IS NOT NULL THEN -p.value ELSE 0 END), 0) as consumed')
            ->selectRaw('COALESCE(SUM(p.value), 0) as available')
            ->first();

        $available = (float) $row->available;

        $result = [
            'character_id' => $mainCharacterId,
            'user_id' => $user->id,
            // 行动审查 / 抽奖可能扣到负值，对外兜底为 0 避免商店出现负余额
            'total_pap' => max(0.0, $available),
            'since' => $since->toDateString(),
        ];

        if ($breakdown) {
            $result['attendance_pap'] = (float) $row->attendance;
            $result['consumed_pap'] = (float) $row->consumed;
            $result['available_pap'] = $available;
        }

        return $result;
    }
}
