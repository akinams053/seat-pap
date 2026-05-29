@extends('web::layouts.grids.12')

@section('title', trans('calendar::seat.plugin_name') . ' | ' . trans('calendar::lottery.list_title'))
@section('page_header', trans('calendar::lottery.list_title'))

@section('full')

    <div class="card">
        <div class="card-header">
            @if($can_manage)
                <a href="{{ route('lottery.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> {{ trans('calendar::lottery.create_btn') }}
                </a>
            @endif
        </div>
        <div class="card-body">
            <table id="lottery-list" class="table table-hover" style="width:100%">
                <thead>
                    <tr>
                        <th>{{ trans('calendar::lottery.col_title') }}</th>
                        <th>{{ trans('calendar::lottery.col_status') }}</th>
                        <th class="text-right">{{ trans('calendar::lottery.col_prizes') }}</th>
                        <th class="text-center">{{ trans('calendar::lottery.col_progress') }}</th>
                        <th class="text-right">{{ trans('calendar::lottery.col_price') }}</th>
                        <th>{{ trans('calendar::lottery.col_fc') }}</th>
                        <th class="text-center">{{ trans('calendar::lottery.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lotteries as $lottery)
                        <tr>
                            <td>{{ $lottery->title }}</td>
                            <td>
                                @php
                                    $badge = [
                                        'open' => 'badge-success',
                                        'sold_out' => 'badge-warning',
                                        'drawn' => 'badge-info',
                                        'cancelled' => 'badge-secondary',
                                    ][$lottery->status] ?? 'badge-secondary';
                                @endphp
                                <span class="badge {{ $badge }}">
                                    {{ trans('calendar::lottery.status_' . $lottery->status) }}
                                </span>
                            </td>
                            <td class="text-right">{{ $lottery->prizes_count }}</td>
                            <td class="text-center" data-order="{{ $lottery->sold_nodes_count }}">
                                {{ $lottery->sold_nodes_count }} / {{ $lottery->node_count }}
                            </td>
                            <td class="text-right">{{ number_format($lottery->node_price, 2) }}</td>
                            <td>{{ optional($lottery->operation)->fc ?? '—' }}</td>
                            <td class="text-center">
                                <a href="{{ route('lottery.show', ['lottery' => $lottery->id]) }}"
                                   class="btn btn-xs btn-info">
                                    <i class="fas fa-search"></i> {{ trans('calendar::lottery.view_btn') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@stop

@push('javascript')
    <script>
        $('#lottery-list').DataTable({
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [6] },
            ],
            language: {
                emptyTable: '{{ trans('calendar::lottery.no_lotteries') }}',
            },
        });
    </script>
@endpush
