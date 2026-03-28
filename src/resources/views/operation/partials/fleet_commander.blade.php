@if($op->fc_character_id)
    @include('web::partials.character', ['character' => $op->fleet_commander])
    @if($op->is_fleet_commander && in_array($op->status, ['ongoing', 'faded']))
        <button type="button" class="btn btn-xs btn-default btn-pap-issue"
                data-op-id="{{ $op->id }}"
                data-op-title="{{ $op->title }}">
            {{ trans('calendar::seat.track_fleet') }}
        </button>
    @endif
@else
    {{ $op->fc }}
@endif
