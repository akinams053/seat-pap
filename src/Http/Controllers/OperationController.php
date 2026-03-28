<?php

namespace Seat\Kassie\Calendar\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Seat\Eseye\Exceptions\EsiScopeAccessDeniedException;
use Seat\Eseye\Exceptions\InvalidContainerDataException;
use Seat\Eseye\Exceptions\RequestFailedException;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Kassie\Calendar\Models\Attendee;
use Seat\Kassie\Calendar\Models\Operation;
use Seat\Kassie\Calendar\Models\Pap;
use Seat\Kassie\Calendar\Models\Tag;
use Seat\Services\Contracts\EsiClient;
use Seat\Services\Exceptions\SettingException;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\Acl\Role;

/**
 * Class OperationController
 * @package Seat\Kassie\Calendar\Http\Controllers
 */
class OperationController extends Controller
{
    /**
     * OperationController constructor.
     */
    public function __construct()
    {
        $this->middleware('can:calendar.view')->only('index');
        $this->middleware('can:calendar.create')->only('store');
    }

    /**
     * @param Request $request
     * @return Factory|View
     * @throws SettingException
     */
    public function index(Request $request): Factory|View
    {
        $tags = Tag::all()->sortBy('order');

        $roles = Role::orderBy('title')->get();
        $user_characters = auth()->user()->characters->sortBy('name');
        $main_character = auth()->user()->main_character;

        if ($main_character != null) {
            $main_character->main = true;
            $user_characters = $user_characters->reject(fn($character): bool => $character->character_id == $main_character->character_id);
            $user_characters->prepend($main_character);
        }

        return view('calendar::operation.index', [
            'roles' => $roles,
            'characters' => $user_characters,
            'default_op' => $request->id ?: 0,
            'tags' => $tags,
        ]);
    }

    /**
     * @param Request $request
     * @throws ValidationException
     */
    public function store(Request $request): void
    {
        $this->validate($request, [
            'title' => 'required',
            'fc' => 'required',
            'importance' => 'required|between:0,5',
            'known_duration' => 'required',
            'time_start' => 'required_without_all:time_start_end|date|after_or_equal:today',
            'time_start_end' => 'required_without_all:time_start'
        ]);

        $operation = new Operation($request->all());
        $tags = [];

        foreach ($request->toArray() as $name => $value) {
            if (empty($value)) {
                $operation->{$name} = null;
            } else if (str_contains($name, 'checkbox-')) {
                $tags[] = $value;
            }
        }

        if ($request->known_duration == "no")
            $operation->start_at = Carbon::parse($request->time_start);
        else {
            $dates = explode(" - ", (string)$request->time_start_end);
            $operation->start_at = Carbon::parse($dates[0]);
            $operation->end_at = Carbon::parse($dates[1]);
        }
        $operation->start_at = Carbon::parse($operation->start_at);

        if ($request->importance == 0)
            $operation->importance = 0;

        $operation->user()->associate(auth()->user());

        $operation->save();

        $operation->tags()->attach($tags);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws ValidationException
     */
    public function update(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'title' => 'required',
            'fc' => 'required',
            'importance' => 'required|between:0,5',
            'known_duration' => 'required',
            'time_start' => 'required_without_all:time_start_end|date|after_or_equal:today',
            'time_start_end' => 'required_without_all:time_start'
        ]);

        $operation = Operation::find($request->operation_id);
        $tags = [];

        if (auth()->user()->can('calendar.update_all') || $operation->user->id == auth()->user()->id) {
            foreach ($request->toArray() as $name => $value) {
                if (empty($value)) {
                    $operation->{$name} = null;
                } else if (str_contains($name, 'checkbox-')) {
                    $tags[] = $value;
                }
            }

            $operation->title = $request->title;
            $operation->role_name = ($request->role_name == "") ? null : $request->role_name;
            $operation->importance = $request->importance;
            $operation->description = $request->description;
            $operation->staging_sys = $request->staging_sys;
            $operation->staging_info = $request->staging_info;
            $operation->staging_sys_id = $request->staging_sys_id == null ? null : $request->staging_sys_id;
            $operation->fc = $request->fc;
            $operation->fc_character_id = $request->fc_character_id == null ? null : $request->fc_character_id;

            if ($request->known_duration == "no") {
                $operation->start_at = Carbon::parse($request->time_start);
                $operation->end_at = null;
            } else {
                $dates = explode(" - ", (string)$request->time_start_end);
                $operation->start_at = Carbon::parse($dates[0]);
                $operation->end_at = Carbon::parse($dates[1]);
            }

            $operation->start_at = Carbon::parse($operation->start_at);

            if ($request->importance == 0)
                $operation->importance = 0;

            $operation->save();

            $operation->tags()->sync($tags);

            return redirect()->route('operation.index');
        }

        return redirect()
            ->back()
            ->with('error', 'An error occurred while processing the request.');
    }

    /**
     * @param $operation_id
     * @return JsonResponse|RedirectResponse
     */
    public function find($operation_id): JsonResponse|RedirectResponse
    {
        if (auth()->user()->can('calendar.view')) {
            $operation = Operation::find($operation_id)->load('tags');

            if (!$operation->isUserGranted(auth()->user()))
                return redirect()->back()->with('error', 'You are not granted to this operation !');

            return response()->json($operation);
        }

        return redirect()
            ->back()
            ->with('error', 'An error occurred while processing the request.');
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function delete(Request $request): RedirectResponse
    {
        $operation = Operation::find($request->operation_id);

        if ((auth()->user()->can('calendar.delete_all') || $operation->user->id == auth()->user()->id) && $operation != null) {
            if (!$operation->isUserGranted(auth()->user()))
                return redirect()->back()->with('error', 'You are not granted to this operation !');

            // 删除行动时同步撤回关联的 PAP
            Pap::where('operation_id', $operation->id)->delete();

            Operation::destroy($operation->id);
            return redirect()->route('operation.index');
        }

        return redirect()
            ->back()
            ->with('error', 'An error occurred while processing the request.');
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function close(Request $request): RedirectResponse
    {
        $operation = Operation::find($request->operation_id);
        if ((auth()->user()->can('calendar.close_all') || $operation->user->id == auth()->user()->id) && $operation != null) {
            $operation->end_at = Carbon::now('UTC');
            $operation->save();
            return redirect()->route('operation.index');
        }

        return redirect()
            ->back()
            ->with('error', 'An error occurred while processing the request.');
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function cancel(Request $request): RedirectResponse
    {
        $operation = Operation::find($request->operation_id);
        if ((auth()->user()->can('calendar.close_all') || $operation->user->id == auth()->user()->id) && $operation != null) {
            $this->changeStatus($operation, true);

            return redirect()->route('operation.index');
        }

        return redirect()
            ->back()
            ->with('error', 'An error occurred while processing the request.');
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function activate(Request $request): RedirectResponse
    {
        $operation = Operation::find($request->operation_id);
        if ((auth()->user()->can('calendar.close_all') || $operation->user->id == auth()->user()->id) && $operation != null) {
            $this->changeStatus($operation, false);
            return redirect()->route('operation.index');
        }

        return redirect()
            ->back()
            ->with('error', 'An error occurred while processing the request.');
    }

    private function changeStatus(Operation $operation, bool $status): void
    {
        $operation->timestamps = false;
        $operation->is_cancelled = $status;
        $operation->save();
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function subscribe(Request $request): RedirectResponse
    {
        $operation = Operation::find($request->operation_id);

        if ($operation != null) {

            if (!$operation->isUserGranted(auth()->user()))
                return redirect()->back()->with('error', 'You are not granted to this operation !');

            if ($operation->status == "incoming") {
                Attendee::updateOrCreate(
                    [
                        'operation_id' => $request->operation_id,
                        'character_id' => $request->character_id
                    ],
                    [
                        'user_id' => auth()->user()->id,
                        'status' => $request->status,
                        'comment' => $request->comment
                    ]
                );
                return redirect()->route('operation.index');
            }
        }

        return redirect()
            ->back()
            ->with('error', 'An error occurred while processing the request.');
    }

    /**
     * PAP 预览：获取舰队成员并对比已发放记录
     */
    public function papsPreview(int $operation_id): JsonResponse
    {
        $check = $this->validatePapAccess($operation_id);
        if ($check instanceof JsonResponse) return $check;

        ['operation' => $operation, 'token' => $token] = $check;

        try {
            $client = $this->eseye($token);
            $fleet = $client->invoke('get', '/v1/characters/{character_id}/fleet/', [
                'character_id' => $token->character_id,
            ]);
            $fleetId = $fleet->getBody()->fleet_id;

            $membersResponse = $client->invoke('get', '/v1/fleets/{fleet_id}/members/', [
                'fleet_id' => $fleetId,
            ]);
            $members = collect($membersResponse->getBody());

            // 已发放的角色 ID
            $existingIds = Pap::where('operation_id', $operation_id)
                ->pluck('character_id')
                ->toArray();

            $isFirstTime = empty($existingIds);
            $newMembers = $members->filter(fn($m) => !in_array($m->character_id, $existingIds));

            // 服务端解析角色名
            $characterNames = \Seat\Eveapi\Models\Character\CharacterInfo::whereIn(
                'character_id', $newMembers->pluck('character_id')
            )->pluck('name', 'character_id');

            return response()->json([
                'status' => 'success',
                'fleet_id' => $fleetId,
                'is_first_time' => $isFirstTime,
                'total_in_fleet' => $members->count(),
                'already_issued' => count($existingIds),
                'new_count' => $newMembers->count(),
                'new_members' => $newMembers->map(fn($m) => [
                    'character_id' => $m->character_id,
                    'name' => $characterNames->get($m->character_id, 'Unknown #' . $m->character_id),
                ])->values(),
            ]);

        } catch (RequestFailedException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getError()], 400);
        } catch (EsiScopeAccessDeniedException $e) {
            return response()->json(['status' => 'error', 'message' => trans('calendar::paps.pap_scope_error')], 403);
        }
    }

    /**
     * PAP 确认发放
     */
    public function papsConfirm(Request $request, int $operation_id): RedirectResponse
    {
        $check = $this->validatePapAccess($operation_id);
        if ($check instanceof JsonResponse) {
            return redirect()->back()->with('error', $check->getData()->message ?? 'Error');
        }

        ['operation' => $operation, 'token' => $token] = $check;
        $client = $this->eseye($token);
        $fleetId = null;

        try {
            $fleet = $client->invoke('get', '/v1/characters/{character_id}/fleet/', [
                'character_id' => $token->character_id,
            ]);
            $fleetId = $fleet->getBody()->fleet_id;

            $membersResponse = $client->invoke('get', '/v1/fleets/{fleet_id}/members/', [
                'fleet_id' => $fleetId,
            ]);
            $members = $membersResponse->getBody();
            $count = is_array($members) ? count($members) : count((array) $members);

            $newCount = 0;
            foreach ($members as $member) {
                $created = Pap::firstOrCreate([
                    'character_id' => $member->character_id,
                    'operation_id' => $operation_id,
                ], [
                    'ship_type_id' => $member->ship_type_id,
                    'join_time' => carbon($member->join_time)->toDateTimeString(),
                ]);
                if ($created->wasRecentlyCreated) $newCount++;
            }

            Log::info("PAP: issued for operation {$operation_id}, fleet {$fleetId}, total {$count}, new {$newCount}");
            $this->updateFleetMotd($client, $fleetId, $operation, $count, true);

        } catch (RequestFailedException $e) {
            Log::warning('PAP: ESI request failed - ' . $e->getCode() . ' - ' . $e->getError());
            if ($fleetId) {
                $this->updateFleetMotd($client, $fleetId, $operation, 0, false, $e->getError());
            }
            return redirect()->back()->with('error', $e->getError());

        } catch (EsiScopeAccessDeniedException $e) {
            Log::warning('PAP: ESI scope access denied for character ' . $token->character_id);
            if ($fleetId) {
                $this->updateFleetMotd($client, $fleetId, $operation, 0, false, 'ESI scope access denied');
            }
            return redirect()->back()->with('error', trans('calendar::paps.pap_scope_error'));
        }

        return redirect()->back()->with('success', trans('calendar::paps.pap_issued_success', ['count' => $newCount, 'total' => $count]));
    }

    /**
     * 校验 PAP 操作权限，返回 operation + token 或错误 JsonResponse
     */
    private function validatePapAccess(int $operationId): array|JsonResponse
    {
        $operation = Operation::find($operationId);
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
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => trans('calendar::paps.pap_no_token')], 400);
        }

        return ['operation' => $operation, 'token' => $token];
    }

    /**
     * PAP 发放后更新舰队 MOTD
     */
    private function updateFleetMotd(
        EsiClient $client,
        int $fleetId,
        Operation $operation,
        int $count,
        bool $success,
        string $errorMessage = ''
    ): void {
        try {
            $motd = $success
                ? $this->buildSuccessMotd($operation, $count)
                : $this->buildErrorMotd($operation, $errorMessage);

            $client->setBody([
                'motd' => $motd,
            ])->invoke('put', '/v1/fleets/{fleet_id}/', [
                'fleet_id' => $fleetId,
            ]);

            Log::info('PAP: fleet MOTD updated for fleet ' . $fleetId);
        } catch (\Throwable $e) {
            // MOTD 更新失败不应影响 PAP 发放结果
            Log::warning('PAP: failed to update fleet MOTD - ' . $e->getMessage());
        }
    }

    private function motdColor(string $key, string $default): string
    {
        return '0xff' . (setting('kassie.calendar.' . $key, true) ?: $default);
    }

    private function buildSuccessMotd(Operation $operation, int $count): string
    {
        $time = carbon()->format('Y-m-d H:i');
        $title = $operation->title;
        $papValue = $operation->tags->max('quantifier') ?: 1;
        $analytics = $operation->tags->pluck('analytics')->filter()->unique()->implode(', ') ?: 'N/A';

        $cHeader  = $this->motdColor('motd_color_header', '00ff00');
        $cFleet   = $this->motdColor('motd_color_fleet', 'ffffff');
        $cMembers = $this->motdColor('motd_color_members', 'ffff00');
        $cPap     = $this->motdColor('motd_color_pap_value', 'ffff00');
        $cType    = $this->motdColor('motd_color_pap_type', '00ffff');
        $cTime    = $this->motdColor('motd_color_time', '00ff00');

        $footerText  = setting('kassie.calendar.motd_footer_text', true) ?: '';
        $footerColor = $this->motdColor('motd_footer_color', 'ffff00');

        $motd = "\n<color={$cHeader}>✦ PAP Issued ✦</color>"
            . "\n<color={$cFleet}>" . trans('calendar::paps.motd_fleet') . "</color> " . $title
            . "\n<color={$cFleet}>" . trans('calendar::paps.motd_members') . "</color> <color={$cMembers}>" . $count . "</color>"
            . "\n<color={$cFleet}>" . trans('calendar::paps.motd_pap_value') . "</color> <color={$cPap}>" . $papValue . "</color>"
            . "\n<color={$cType}>" . trans('calendar::paps.motd_type') . "</color> [" . $analytics . "]"
            . "\n<color={$cTime}>" . trans('calendar::paps.motd_time') . "</color> " . $time . " EVE";

        if ($footerText !== '') {
            $motd .= "\n<color={$footerColor}>" . $footerText . "</color>";
        }

        return $motd;
    }

    private function buildErrorMotd(Operation $operation, string $errorMessage): string
    {
        $time = carbon()->format('Y-m-d H:i');
        $title = $operation->title;

        $cError  = $this->motdColor('motd_color_error', 'ff0000');
        $cFleet  = $this->motdColor('motd_color_fleet', 'ffffff');
        $cTime   = $this->motdColor('motd_color_time', '00ff00');

        $footerText  = setting('kassie.calendar.motd_footer_text', true) ?: '';
        $footerColor = $this->motdColor('motd_footer_color', 'ffff00');

        $motd = "\n<color={$cError}>✦ " . trans('calendar::paps.motd_error_title') . " ✦</color>"
            . "\n<color={$cFleet}>" . trans('calendar::paps.motd_fleet') . "</color> " . $title
            . "\n<color={$cError}>" . $errorMessage . "</color>"
            . "\n<color={$cTime}>" . trans('calendar::paps.motd_time') . "</color> " . $time . " EVE";

        if ($footerText !== '') {
            $motd .= "\n<color={$footerColor}>" . $footerText . "</color>";
        }

        return $motd;
    }

    /**
     * @param RefreshToken $token
     * @return EsiClient
     * @throws InvalidContainerDataException|BindingResolutionException
     */
    private function eseye(RefreshToken $token): EsiClient
    {
        $client = app()->make(EsiClient::class);
        $client->setAuthentication($token);

        return $client;
    }

}
