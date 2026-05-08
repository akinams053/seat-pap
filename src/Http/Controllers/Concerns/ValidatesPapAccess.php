<?php

namespace Seat\Kassie\Calendar\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Kassie\Calendar\Models\Operation;

/**
 * PAP 操作（发放、审查）的统一 FC 校验。
 * 任一失败立刻返回 JsonResponse；全部通过返回 ['operation' => ..., 'token' => ...]。
 */
trait ValidatesPapAccess
{
    protected function validatePapAccess(int $operationId): array|JsonResponse
    {
        $operation = Operation::with('tags')->find($operationId);
        if (is_null($operation))
            return response()->json(['status' => 'error', 'message' => 'Operation not found.'], 404);

        if (!$operation->isUserGranted(auth()->user()))
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);

        if (is_null($operation->fc_character_id))
            return response()->json(['status' => 'error', 'message' => trans('calendar::paps.pap_no_fc')], 400);

        if (!in_array($operation->fc_character_id, auth()->user()->associatedCharacterIds()))
            return response()->json(['status' => 'error', 'message' => trans('calendar::paps.pap_not_fc')], 403);

        try {
            $token = RefreshToken::findOrFail($operation->fc_character_id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => trans('calendar::paps.pap_no_token')], 400);
        }

        return ['operation' => $operation, 'token' => $token];
    }
}
