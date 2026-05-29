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
                    {{-- 购买区占位：阶段 3 完善 --}}
                    <div class="alert alert-secondary">
                        {{ trans('calendar::lottery.purchase_coming_soon') }}
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
    </script>
@endpush
