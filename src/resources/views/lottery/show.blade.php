@extends('web::layouts.grids.12')

@section('title', trans('calendar::seat.plugin_name') . ' | ' . $lottery->title)
@section('page_header', $lottery->title)

@section('full')

    @php
        $badge = [
            'open' => 'badge-success',
            'sold_out' => 'badge-warning',
            'drawn' => 'badge-info',
            'cancelled' => 'badge-secondary',
        ][$lottery->status] ?? 'badge-secondary';

        // 中奖节点编号 => 奖品名
        $winnerNodes = [];
        foreach ($lottery->prizes as $prize) {
            if (! is_null($prize->winner_node_number)) {
                $winnerNodes[$prize->winner_node_number] = $prize->name;
            }
        }
    @endphp

    {{-- 管理操作（FC / 管理员） --}}
    @if($can_manage && in_array($lottery->status, ['open', 'sold_out']))
        <div class="card">
            <div class="card-body">
                <strong class="mr-2">{{ trans('calendar::lottery.manage_title') }}：</strong>
                @if($lottery->status === 'sold_out')
                    <button type="button" class="btn btn-warning" id="draw-btn">
                        <i class="fas fa-dice"></i> {{ trans('calendar::lottery.draw_btn') }}
                    </button>
                @elseif($lottery->status === 'open' && $sold_count > 0)
                    {{-- 凑不满人时允许 FC 提前开奖（未售节点将作废） --}}
                    <button type="button" class="btn btn-warning" id="draw-btn" data-early="1">
                        <i class="fas fa-dice"></i> {{ trans('calendar::lottery.draw_early_btn') }}
                    </button>
                @endif
                <button type="button" class="btn btn-outline-danger" id="cancel-btn">
                    <i class="fas fa-ban"></i> {{ trans('calendar::lottery.cancel_btn') }}
                </button>
            </div>
        </div>
    @endif

    @if($lottery->status === 'drawn' && $lottery->drawn_at)
        <div class="alert alert-info">
            {{ trans('calendar::lottery.drawn_info', [
                'by' => $char_names->get($lottery->drawn_by_character_id, '#' . $lottery->drawn_by_character_id),
                'at' => optional($lottery->drawn_at)->toDateTimeString(),
            ]) }}
        </div>
    @endif

    <div class="row">
        {{-- 左侧：概览 + 奖品 --}}
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <span class="badge {{ $badge }} mb-2">
                        {{ trans('calendar::lottery.status_' . $lottery->status) }}
                    </span>
                    <table class="table table-sm mb-0">
                        <tr>
                            <th>{{ trans('calendar::lottery.col_progress') }}</th>
                            <td>{{ trans('calendar::lottery.progress_label', ['sold' => $sold_count, 'total' => $lottery->node_count]) }}</td>
                        </tr>
                        <tr>
                            <th>{{ trans('calendar::lottery.price_label') }}</th>
                            <td>{{ number_format($lottery->node_price, 2) }} PAP</td>
                        </tr>
                        <tr>
                            <th>{{ trans('calendar::lottery.form_max_per_user') }}</th>
                            <td>{{ $lottery->max_nodes_per_user ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>{{ trans('calendar::lottery.fc_label') }}</th>
                            <td>{{ optional($lottery->operation)->fc ?? '—' }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>{{ trans('calendar::lottery.prizes_header') }}</strong></div>
                <div class="card-body">
                    <ul class="list-group">
                        @foreach($lottery->prizes as $prize)
                            <li class="list-group-item">
                                <strong>{{ $prize->name }}</strong>
                                @if($prize->description)
                                    <div class="text-muted small">{{ $prize->description }}</div>
                                @endif
                                @if(! is_null($prize->winner_node_number))
                                    <div class="small">
                                        <span class="badge badge-warning">
                                            {{ trans('calendar::lottery.winner_node') }} #{{ $prize->winner_node_number }}
                                        </span>
                                        {{ $char_names->get($prize->winner_character_id, '#' . $prize->winner_character_id) }}
                                    </div>
                                @else
                                    <div class="small text-muted">{{ trans('calendar::lottery.not_drawn_yet') }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        {{-- 右侧：节点格子 --}}
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <strong>{{ trans('calendar::lottery.nodes_header') }}</strong>
                    <span class="float-right small">
                        <span class="lottery-node node-unsold">&nbsp;</span> {{ trans('calendar::lottery.node_unsold') }}
                        <span class="lottery-node node-mine">&nbsp;</span> {{ trans('calendar::lottery.node_mine') }}
                        <span class="lottery-node node-others">&nbsp;</span> {{ trans('calendar::lottery.node_others') }}
                        <span class="lottery-node node-winner">&nbsp;</span> {{ trans('calendar::lottery.node_winner') }}
                    </span>
                </div>
                <div class="card-body">
                    @php
                        $myNodeNumbers = $lottery->nodes
                            ->filter(fn($n) => $n->user_id == $my_user_id
                                && ! is_null($n->purchased_at) && is_null($n->refunded_at) && is_null($n->voided_at))
                            ->pluck('node_number')->sort()->values();
                    @endphp

                    {{-- 购买区 --}}
                    <div class="border rounded p-2 mb-3" id="buy-panel">
                        <div class="row text-center mb-2">
                            <div class="col">
                                <div class="text-muted small">{{ trans('calendar::lottery.available_pap_label') }}</div>
                                <strong id="available-pap">{{ number_format($available_pap, 2) }}</strong>
                            </div>
                            <div class="col">
                                <div class="text-muted small">{{ trans('calendar::lottery.remaining_label') }}</div>
                                <strong id="remaining-count">{{ $remaining_count }}</strong>
                            </div>
                            <div class="col">
                                <div class="text-muted small">{{ trans('calendar::lottery.my_owned_label') }}</div>
                                <strong>{{ $my_owned }}</strong>
                            </div>
                            <div class="col">
                                <div class="text-muted small">{{ trans('calendar::lottery.price_label') }}</div>
                                <strong>{{ number_format($lottery->node_price, 2) }}</strong>
                            </div>
                        </div>

                        @if($lottery->status === 'open')
                            <div class="input-group">
                                <input type="number" id="buy-quantity" class="form-control" min="1" step="1" value="1">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-primary" id="buy-btn">
                                        <i class="fas fa-shopping-cart"></i> {{ trans('calendar::lottery.buy_btn') }}
                                    </button>
                                </div>
                            </div>
                            <small class="form-text text-muted" id="max-buyable-hint"></small>
                        @else
                            <div class="alert alert-secondary mb-0">{{ trans('calendar::lottery.buy_closed') }}</div>
                        @endif

                        @if($myNodeNumbers->isNotEmpty())
                            <div class="mt-2 small">
                                <span class="text-muted">{{ trans('calendar::lottery.my_nodes') }}：</span>
                                @foreach($myNodeNumbers as $num)
                                    <span class="badge badge-success">#{{ $num }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="lottery-node-grid">
                        @foreach($lottery->nodes as $node)
                            @php
                                $owned = ! is_null($node->purchased_at) && is_null($node->refunded_at) && is_null($node->voided_at);
                                $isWinner = array_key_exists($node->node_number, $winnerNodes);
                                $isMine = $owned && $node->user_id == $my_user_id;
                                if ($isWinner) $cls = 'node-winner';
                                elseif (! $owned) $cls = 'node-unsold';
                                elseif ($isMine) $cls = 'node-mine';
                                else $cls = 'node-others';

                                $tip = '#' . $node->node_number;
                                if ($owned) {
                                    $tip .= ' · ' . $char_names->get($node->character_id, '#' . $node->character_id);
                                }
                                if ($isWinner) {
                                    $tip .= ' · ' . $winnerNodes[$node->node_number];
                                }
                            @endphp
                            <span class="lottery-node {{ $cls }}" data-toggle="tooltip" title="{{ $tip }}">
                                {{ $node->node_number }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <a href="{{ route('lottery.index') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> {{ trans('calendar::lottery.back_to_list') }}
    </a>

@stop

@push('head')
    <style>
        .lottery-node-grid { display: flex; flex-wrap: wrap; gap: 3px; }
        .lottery-node {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 34px; height: 28px; padding: 0 4px;
            font-size: 0.75rem; border-radius: 3px; color: #fff; cursor: default;
        }
        .node-unsold { background: #adb5bd; color: #333; }
        .node-others { background: #3c8dbc; }
        .node-mine   { background: #28a745; }
        .node-winner { background: #f39c12; font-weight: bold; }
    </style>
@endpush

@push('javascript')
    <script>
        $('[data-toggle="tooltip"]').tooltip();

        @if($lottery->status === 'open')
        (function () {
            var price = {{ $lottery->node_price }};
            var available = {{ $available_pap }};
            var remaining = {{ $remaining_count }};
            var myOwned = {{ $my_owned }};
            var maxPerUser = {{ $lottery->max_nodes_per_user ?? 'null' }};
            var purchaseUrl = '{{ route('lottery.purchase', ['lottery' => $lottery->id]) }}';
            var csrf = '{{ csrf_token() }}';

            // 本次最多可购：受剩余、余额、每人上限三者约束
            function maxBuyable() {
                var byBalance = price > 0 ? Math.floor(available / price) : 0;
                var max = Math.min(remaining, byBalance);
                if (maxPerUser !== null) {
                    max = Math.min(max, maxPerUser - myOwned);
                }
                return Math.max(0, max);
            }

            function refreshHint() {
                var max = maxBuyable();
                $('#max-buyable-hint').text('{{ trans('calendar::lottery.max_buyable', ['n' => ':N']) }}'.replace(':N', max));
                if (max <= 0) {
                    $('#buy-btn').prop('disabled', true);
                }
            }
            refreshHint();

            $('#buy-btn').on('click', function () {
                var qty = parseInt($('#buy-quantity').val(), 10);
                var max = maxBuyable();
                if (!(qty >= 1)) { return; }
                if (qty > max) {
                    alert('{{ trans('calendar::lottery.max_buyable', ['n' => ':N']) }}'.replace(':N', max));
                    return;
                }
                var spent = (qty * price).toFixed(2);
                if (!confirm('{{ trans('calendar::lottery.buy_confirm', ['count' => ':C', 'spent' => ':S']) }}'
                        .replace(':C', qty).replace(':S', spent))) {
                    return;
                }

                var $btn = $(this).prop('disabled', true);
                $.ajax({
                    url: purchaseUrl,
                    method: 'POST',
                    data: { _token: csrf, quantity: qty },
                    success: function (resp) {
                        // 购买成功后刷新整页，保证节点/余额/进度均以服务端为准（§6.4）
                        window.location.reload();
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message)
                            || (xhr.responseJSON && xhr.responseJSON.errors
                                && Object.values(xhr.responseJSON.errors).flat().join('; '))
                            || 'Error';
                        alert(msg);
                        $btn.prop('disabled', false);
                    }
                });
            });
        })();
        @endif

        @if($can_manage && ($lottery->status === 'sold_out' || ($lottery->status === 'open' && $sold_count > 0)))
        $('#draw-btn').on('click', function () {
            var isEarly = $(this).data('early') == 1;
            var drawMsg = isEarly
                ? '{{ trans('calendar::lottery.draw_early_confirm') }}'
                : '{{ trans('calendar::lottery.draw_confirm') }}';
            if (!confirm(drawMsg)) {
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: '{{ route('lottery.draw', ['lottery' => $lottery->id]) }}',
                method: 'POST',
                data: { _token: '{{ csrf_token() }}' },
                success: function () {
                    window.location.reload();
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Error';
                    alert(msg);
                    $btn.prop('disabled', false);
                }
            });
        });
        @endif

        @if($can_manage && in_array($lottery->status, ['open', 'sold_out']))
        $('#cancel-btn').on('click', function () {
            if (!confirm('{{ trans('calendar::lottery.cancel_confirm') }}')) {
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: '{{ route('lottery.cancel', ['lottery' => $lottery->id]) }}',
                method: 'POST',
                data: { _token: '{{ csrf_token() }}' },
                success: function () {
                    window.location.reload();
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Error';
                    alert(msg);
                    $btn.prop('disabled', false);
                }
            });
        });
        @endif

        @if(in_array($lottery->status, ['open', 'sold_out']))
        (function () {
            var snapshotUrl = '{{ route('lottery.snapshot', ['lottery' => $lottery->id]) }}';
            var currentSnapshotVersion = @json($snapshot['version']);
            var pollInFlight = false;

            setInterval(function () {
                if (document.hidden || pollInFlight) {
                    return;
                }

                pollInFlight = true;
                $.ajax({
                    url: snapshotUrl,
                    method: 'GET',
                    dataType: 'json',
                    success: function (resp) {
                        var snapshot = resp && resp.snapshot ? resp.snapshot : null;
                        if (!snapshot) {
                            return;
                        }

                        if (snapshot.version !== currentSnapshotVersion
                            || ['drawn', 'cancelled'].indexOf(snapshot.lottery_status) !== -1) {
                            window.location.reload();
                        }
                    },
                    complete: function () {
                        pollInFlight = false;
                    }
                });
            }, 5000);
        })();
        @endif
    </script>
@endpush
