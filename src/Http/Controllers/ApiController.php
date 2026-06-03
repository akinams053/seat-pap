<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Kassie\Calendar\Models\Lottery;
use Seat\Kassie\Calendar\Models\Operation;
use Seat\Kassie\Calendar\Models\Pap;
use Seat\Kassie\Calendar\Models\PapAdjustment;

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
     * 商店预留实时扣减：POST /api/calendar/paps/debit
     *
     * 抽奖不再调用本接口；抽奖开奖后统一走 lotterySettle()。
     */
    public function debit(Request $request): JsonResponse
    {
        return $this->handleLedgerWrite($request, true);
    }

    /**
     * 商店预留退款：POST /api/calendar/paps/refund
     *
     * 写正数调整，不校验余额（外部负责不超退），幂等键独立。
     */
    public function refund(Request $request): JsonResponse
    {
        return $this->handleLedgerWrite($request, false);
    }

    /**
     * 抽奖开奖结算：一场抽奖写成一个消费 operation，并回到行动审查页纠错。
     */
    public function lotterySettle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ref_group' => 'required|string|max:64',
            'settled_by_character_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'idempotency_key' => 'required|string|max:128',
            'participants' => 'required|array|min:1',
            'participants.*.character_id' => 'required|integer',
            'participants.*.amount' => 'required|numeric|min:0.01|max:999999.99',
            'participants.*.reason' => 'required|string|max:255',
        ]);

        $idempotencyKey = $validated['idempotency_key'];
        $settleKey = $this->lotterySettleKey($idempotencyKey);
        $lockName = $this->lotterySettleLockName($validated['ref_group']);
        $userLocks = [];

        $lock = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if (! $lock || (int) $lock->acquired !== 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Lottery settlement is busy, please retry.',
            ], 503);
        }

        try {
            $settler = $this->resolveLinkedCharacter((int) $validated['settled_by_character_id']);
            if ($settler === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Settler character not found or not linked to a SeAT user.',
                ], 404);
            }

            $resolvedParticipants = [];
            $amountByMainCharacter = [];
            $participantUserIds = [];

            foreach ($validated['participants'] as $participant) {
                $participantCharacterId = (int) $participant['character_id'];
                $resolved = $this->resolveLinkedCharacter($participantCharacterId);

                if ($resolved === null) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Participant character not found or not linked to a SeAT user.',
                        'character_id' => $participantCharacterId,
                    ], 422);
                }

                $amount = round((float) $participant['amount'], 2);
                $mainCharacterId = $resolved['main_character_id'];
                $amountByMainCharacter[$mainCharacterId] = round(($amountByMainCharacter[$mainCharacterId] ?? 0) + $amount, 2);

                if ($amountByMainCharacter[$mainCharacterId] > 999999.99) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Participant total amount exceeds PAP value limit.',
                        'character_id' => $mainCharacterId,
                    ], 422);
                }

                $resolvedParticipants[] = [
                    ...$resolved,
                    'amount' => $amount,
                    'reason' => $participant['reason'],
                ];
                $participantUserIds[$resolved['user_id']] = true;
            }

            $acquiredUserLocks = $this->acquireUserLocks(array_keys($participantUserIds));
            if ($acquiredUserLocks === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account is busy, please retry.',
                ], 503);
            }
            $userLocks = $acquiredUserLocks;

            $existing = Operation::where('consumption_key', $settleKey)->first();
            $existingByRefGroup = $this->findLotterySettlementByRefGroup($validated['ref_group']);
            if ($existing && $existingByRefGroup && (int) $existing->id !== (int) $existingByRefGroup->id) {
                return $this->lotterySettlementConflictResponse();
            }

            $existingSettlement = $existing ?: $existingByRefGroup;
            if ($existingSettlement) {
                if (! $this->lotterySettlementMatches($existingSettlement, $validated['ref_group'], $resolvedParticipants)) {
                    return $this->lotterySettlementConflictResponse();
                }

                return $this->lotterySettleResponse($existingSettlement, $idempotencyKey, true, $resolvedParticipants);
            }

            try {
                $operation = DB::transaction(function () use ($validated, $settleKey, $settler, $resolvedParticipants): Operation {
                    $now = carbon();
                    $operation = new Operation([
                        'title' => $validated['title'],
                        'is_consumption' => true,
                        'importance' => 0,
                        'start_at' => $now,
                        'end_at' => $now,
                        'consumption_key' => $settleKey,
                    ]);
                    $operation->user_id = $settler['user_id'];
                    $operation->fc = $settler['name'];
                    $operation->fc_character_id = $settler['main_character_id'];
                    $operation->save();

                    $operation->tags()->syncWithoutDetaching([Lottery::reservedTag()->id]);
                    $touchedMainCharacters = [];

                    foreach ($resolvedParticipants as $participant) {
                        Pap::firstOrCreate(
                            ['operation_id' => $operation->id, 'character_id' => $participant['main_character_id']],
                            ['ship_type_id' => 0, 'join_time' => $now->toDateTimeString(), 'created_at' => $now]
                        );

                        PapAdjustment::create([
                            'operation_id' => $operation->id,
                            'character_id' => $participant['main_character_id'],
                            'value' => -$participant['amount'],
                            'source' => 'lottery',
                            'external_ref' => null,
                            'ref_group' => $validated['ref_group'],
                            'reason' => $participant['reason'],
                            'created_by_character_id' => $settler['main_character_id'],
                            'created_at' => $now,
                        ]);

                        $touchedMainCharacters[$participant['main_character_id']] = true;
                    }

                    foreach (array_keys($touchedMainCharacters) as $mainCharacterId) {
                        Pap::recomputeValueFor($operation->id, (int) $mainCharacterId);
                    }

                    return $operation;
                });
            } catch (UniqueConstraintViolationException $e) {
                $operation = Operation::where('consumption_key', $settleKey)->first();
                if (! $operation) {
                    throw $e;
                }

                if (! $this->lotterySettlementMatches($operation, $validated['ref_group'], $resolvedParticipants)) {
                    return $this->lotterySettlementConflictResponse();
                }

                return $this->lotterySettleResponse($operation, $idempotencyKey, true, $resolvedParticipants);
            }

            return $this->lotterySettleResponse($operation, $idempotencyKey, false, $resolvedParticipants);
        } finally {
            $this->releaseLocks($userLocks);
            DB::statement('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * debit / refund 共享实现。仅保留给未来商店；抽奖不得通过 standing operation 实时扣款。
     */
    private function handleLedgerWrite(Request $request, bool $isDebit): JsonResponse
    {
        $validated = $request->validate([
            'character_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'merchant' => 'required|string|max:32',
            'idempotency_key' => 'required|string|max:128',
            'ref_group' => 'nullable|string|max:64',
            'reason' => 'nullable|string|max:255',
        ]);

        $amount = round((float) $validated['amount'], 2);
        $merchant = $validated['merchant'];
        $key = $validated['idempotency_key'];
        $refGroup = $validated['ref_group'] ?? null;
        $reason = $validated['reason'] ?? sprintf('%s %s', $merchant, $isDebit ? 'debit' : 'refund');

        $token = RefreshToken::find($validated['character_id']);
        $user = $token?->user;

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Character not found or not linked to a SeAT user.',
            ], 404);
        }

        $mainCharacterId = $user->main_character_id ?? (int) $validated['character_id'];
        $characterIds = $user->associatedCharacterIds();

        // 按 user 串行化：根除跨商户/跨场次并发双花（见计划 §10）
        $lockName = 'pap_calendar_user_' . $user->id;
        $lock = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if (! $lock || (int) $lock->acquired !== 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Account is busy, please retry.',
            ], 503);
        }

        try {
            // 幂等：同 idempotency_key 命中直接回放上次结果（不重复扣）
            $existing = PapAdjustment::where('external_ref', $key)->first();
            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'balance_after' => round($this->availableBalance($characterIds), 2),
                    'adjustment_id' => $existing->id,
                    'idempotency_key' => $key,
                    'idempotent_replay' => true,
                ]);
            }

            $balance = $this->availableBalance($characterIds);

            if ($isDebit && $amount > $balance + 0.0001) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient balance.',
                    'balance' => round($balance, 2),
                ], 409);
            }

            $adjustment = DB::transaction(function () use ($isDebit, $amount, $merchant, $key, $refGroup, $reason, $mainCharacterId, $user): PapAdjustment {
                $now = carbon();
                $standing = Operation::standingFor($merchant, $now, $user->id);

                // 确保该商户当月锚下主角色的 paps 行存在（ship_type_id NOT NULL，占位 0）
                Pap::firstOrCreate(
                    ['operation_id' => $standing->id, 'character_id' => $mainCharacterId],
                    ['ship_type_id' => 0, 'join_time' => $now->toDateTimeString(), 'created_at' => $now]
                );

                $adjustment = PapAdjustment::create([
                    'operation_id' => $standing->id,
                    'character_id' => $mainCharacterId,
                    'value' => $isDebit ? -$amount : $amount,
                    'source' => $merchant,
                    'external_ref' => $key,
                    'ref_group' => $refGroup,
                    'reason' => $reason,
                    'created_by_character_id' => $mainCharacterId,
                    'created_at' => $now,
                ]);

                // 回写 paps.value（base 0 + Σ调整），下游统计/余额零改动同步
                Pap::recomputeValueFor($standing->id, $mainCharacterId);

                return $adjustment;
            });

            return response()->json([
                'status' => 'success',
                'balance_after' => round($this->availableBalance($characterIds), 2),
                'adjustment_id' => $adjustment->id,
                'idempotency_key' => $key,
                'idempotent_replay' => false,
            ]);
        } finally {
            DB::statement('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * 主角色聚合后的当前可用余额 = SUM(paps.value)（口径同 resolvePap，内部计算不做 max(0) 兜底）。
     */
    private function availableBalance(array $characterIds): float
    {
        return (float) DB::table('kassie_calendar_paps')
            ->whereIn('character_id', $characterIds)
            ->where('join_time', '>=', Pap::statisticsStartDate())
            ->sum('value');
    }

    private function lotterySettleKey(string $idempotencyKey): string
    {
        return 'lottery-settle:' . hash('sha256', $idempotencyKey);
    }

    private function lotterySettleLockName(string $refGroup): string
    {
        return 'pap_lottery_' . substr(hash('sha256', $refGroup), 0, 32);
    }

    private function lotterySettleResponse(Operation $operation, string $idempotencyKey, bool $idempotentReplay, array $resolvedParticipants): JsonResponse
    {
        $participants = collect($resolvedParticipants)
            ->groupBy('main_character_id')
            ->map(fn($items, $mainCharacterId) => [
                'character_id' => (int) $mainCharacterId,
                'balance_after' => round($this->availableBalance($items->first()['associated_character_ids']), 2),
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'operation_id' => $operation->id,
            'idempotency_key' => $idempotencyKey,
            'idempotent_replay' => $idempotentReplay,
            'participants' => $participants,
        ]);
    }

    private function lotterySettlementConflictResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Lottery settlement key or ref_group already exists with different payload.',
        ], 409);
    }

    private function findLotterySettlementByRefGroup(string $refGroup): ?Operation
    {
        $operationIds = PapAdjustment::where('source', 'lottery')
            ->where('ref_group', $refGroup)
            ->distinct()
            ->limit(2)
            ->pluck('operation_id');

        if ($operationIds->isEmpty()) {
            return null;
        }

        return Operation::find((int) $operationIds->first());
    }

    private function lotterySettlementMatches(Operation $operation, string $refGroup, array $resolvedParticipants): bool
    {
        $existingRefGroups = PapAdjustment::where('operation_id', $operation->id)
            ->where('source', 'lottery')
            ->distinct()
            ->pluck('ref_group')
            ->reject(fn($ref) => is_null($ref))
            ->values();

        if ($existingRefGroups->count() !== 1 || $existingRefGroups->first() !== $refGroup) {
            return false;
        }

        $existingParticipants = PapAdjustment::where('operation_id', $operation->id)
            ->where('source', 'lottery')
            ->where('ref_group', $refGroup)
            ->get(['character_id', 'value', 'reason'])
            ->map(fn($adjustment) => [
                'main_character_id' => (int) $adjustment->character_id,
                'amount' => round(abs((float) $adjustment->value), 2),
                'reason' => (string) $adjustment->reason,
            ])
            ->all();

        return $this->lotteryParticipantsSignature($existingParticipants) === $this->lotteryParticipantsSignature($resolvedParticipants);
    }

    private function lotteryParticipantsSignature(array $participants): array
    {
        return collect($participants)
            ->map(fn($participant) => sprintf(
                '%d|%s|%s',
                (int) $participant['main_character_id'],
                number_format((float) $participant['amount'], 2, '.', ''),
                (string) $participant['reason']
            ))
            ->sort()
            ->values()
            ->all();
    }

    private function resolveLinkedCharacter(int $characterId): ?array
    {
        $token = RefreshToken::find($characterId);
        $user = $token?->user;

        if (! $user) {
            return null;
        }

        $mainCharacterId = (int) ($user->main_character_id ?? $characterId);

        return [
            'user_id' => (int) $user->id,
            'main_character_id' => $mainCharacterId,
            'associated_character_ids' => $user->associatedCharacterIds(),
            'name' => CharacterInfo::find($mainCharacterId)?->name ?? '#' . $mainCharacterId,
        ];
    }

    private function acquireUserLocks(array $userIds): ?array
    {
        $lockNames = [];
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        sort($userIds);

        foreach ($userIds as $userId) {
            $lockName = 'pap_calendar_user_' . $userId;
            $lock = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (! $lock || (int) $lock->acquired !== 1) {
                $this->releaseLocks($lockNames);

                return null;
            }

            $lockNames[] = $lockName;
        }

        return $lockNames;
    }

    private function releaseLocks(array $lockNames): void
    {
        foreach (array_reverse($lockNames) as $lockName) {
            DB::statement('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
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

        // 消费 operation 通过 is_consumption 识别，带符号三口径，恒等：出勤 - 消费 = 可用
        $row = DB::table('kassie_calendar_paps as p')
            ->join('calendar_operations as o', 'o.id', '=', 'p.operation_id')
            ->whereIn('p.character_id', $characterIds)
            ->where('p.join_time', '>=', $since)
            ->selectRaw('COALESCE(SUM(CASE WHEN o.is_consumption = 0 THEN p.value ELSE 0 END), 0) as attendance')
            ->selectRaw('COALESCE(SUM(CASE WHEN o.is_consumption = 1 THEN -p.value ELSE 0 END), 0) as consumed')
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
