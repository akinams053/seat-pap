@php
    $limit = $limit ?? 15;
    $maxQty = $ranking->max('qty') ?: 1;
@endphp
<table class="table table-hover mb-0">
    <thead>
    <tr>
        <th style="width: 45px;" class="text-center">#</th>
        <th>{{ trans('calendar::paps.character_header') }}</th>
        <th style="width: 160px;">{{ trans('calendar::paps.paps_header') }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($ranking->take($limit) as $pap)
        <tr @if(isset($highlightId) && $pap->character_id == $highlightId) class="table-active font-weight-bold" @endif>
            <td class="text-center align-middle">
                @if($loop->iteration <= 3)
                    <i class="fas fa-trophy" style="color: {{ [1 => '#FFD700', 2 => '#C0C0C0', 3 => '#CD7F32'][$loop->iteration] }};"></i>
                @else
                    {{ $loop->iteration }}
                @endif
            </td>
            <td class="align-middle">
                @if($pap->character)
                    @include('web::partials.character', ['character' => $pap->character])
                @else
                    {{ trans('web::seat.unknown') }}
                @endif
            </td>
            <td class="align-middle">
                <div class="d-flex align-items-center">
                    <div class="progress flex-grow-1 mr-2" style="height: 16px;">
                        <div class="progress-bar bg-info" role="progressbar"
                             style="width: {{ round($pap->qty / $maxQty * 100) }}%"></div>
                    </div>
                    <strong>{{ $pap->qty }}</strong>
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="3" class="text-center text-muted">{{ $emptyMessage }}</td>
        </tr>
    @endforelse
    </tbody>
    @if(isset($highlightId))
        @php
            $inTopN = $ranking->take($limit)->contains(fn($p) => $p->character_id == $highlightId);
            $myEntry = $ranking->firstWhere('character_id', $highlightId);
        @endphp
        @if(!$inTopN && $myEntry)
            @php $myPosition = $ranking->search(fn($p) => $p->character_id == $highlightId) + 1; @endphp
            <tfoot>
            <tr class="table-active font-weight-bold">
                <td class="text-center align-middle">{{ $myPosition }}</td>
                <td class="align-middle">
                    @if($myEntry->character)
                        @include('web::partials.character', ['character' => $myEntry->character])
                    @else
                        {{ trans('web::seat.unknown') }}
                    @endif
                </td>
                <td class="align-middle">
                    <div class="d-flex align-items-center">
                        <div class="progress flex-grow-1 mr-2" style="height: 16px;">
                            <div class="progress-bar bg-info" role="progressbar"
                                 style="width: {{ round($myEntry->qty / $maxQty * 100) }}%"></div>
                        </div>
                        <strong>{{ $myEntry->qty }}</strong>
                    </div>
                </td>
            </tr>
            </tfoot>
        @endif
    @endif
</table>
