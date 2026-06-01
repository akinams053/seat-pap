@extends('web::layouts.grids.12')

@section('title', trans('calendar::seat.plugin_name') . ' | ' . trans('calendar::seat.consumption_audit'))
@section('page_header', trans('calendar::seat.consumption_audit'))

@section('full')

    {{-- 场次逐笔流水 modal --}}
    <div class="modal fade" id="modalConsumptionDetail" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info">
                    <h4 class="modal-title">
                        <i class="fas fa-coins"></i> <span id="consumption-detail-title"></span>
                    </h4>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div id="consumption-detail-loading" class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x"></i>
                    </div>
                    <div class="table-responsive d-none" id="consumption-detail-wrap">
                        <table class="table table-condensed table-hover">
                            <thead>
                                <tr>
                                    <th>{{ trans('calendar::seat.consumption_col_member') }}</th>
                                    <th class="text-right">{{ trans('calendar::seat.consumption_col_amount') }}</th>
                                    <th>{{ trans('calendar::seat.consumption_col_reason') }}</th>
                                    <th>{{ trans('calendar::seat.consumption_col_time') }}</th>
                                </tr>
                            </thead>
                            <tbody id="consumption-detail-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <p class="text-muted">{{ trans('calendar::seat.consumption_hint') }}</p>
            <table id="consumption-table" class="table table-hover" style="width:100%">
                <thead>
                    <tr>
                        <th>{{ trans('calendar::seat.consumption_col_session') }}</th>
                        <th>{{ trans('calendar::seat.consumption_col_merchant') }}</th>
                        <th class="text-right">{{ trans('calendar::seat.consumption_col_participants') }}</th>
                        <th class="text-right">{{ trans('calendar::seat.consumption_col_debited') }}</th>
                        <th class="text-right">{{ trans('calendar::seat.consumption_col_refunded') }}</th>
                        <th class="text-right">{{ trans('calendar::seat.consumption_col_net') }}</th>
                        <th>{{ trans('calendar::seat.consumption_col_last') }}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>

@stop

@push('head')
    <link rel="stylesheet" href="{{ asset('web/css/calendar.css') }}"/>
@endpush

@push('javascript')
    <script>
        var consumption_url = {
            list: '{{ route('audit.consumption.json') }}',
            detail: '{{ route('audit.consumption.detail') }}',
        };

        function escapeHtml(str) {
            return $('<div>').text(str == null ? '' : String(str)).html();
        }

        $('#consumption-table').DataTable({
            ajax: { url: consumption_url.list },
            order: [[6, 'desc']],
            columns: [
                {data: 'title', render: function (d, t, row) {
                    var label = escapeHtml(d || row.ref_key);
                    return '<a href="#" class="consumption-open" data-ref="' + escapeHtml(row.ref_key) + '" ' +
                        'data-title="' + label + '">' + label + '</a>';
                }},
                {data: 'merchant', render: function (d) { return escapeHtml(d || '—'); }},
                {data: 'participants', className: 'text-right'},
                {data: 'debited', className: 'text-right', render: function (d) { return Number(d).toFixed(2); }},
                {data: 'refunded', className: 'text-right', render: function (d) { return Number(d).toFixed(2); }},
                {data: 'net_consumed', className: 'text-right', render: function (d) {
                    return '<strong>' + Number(d).toFixed(2) + '</strong>';
                }},
                {data: 'last_at', render: function (d) { return d || '<span class="text-muted">—</span>'; }},
            ],
            language: {
                emptyTable: '{{ trans('calendar::paps.no_data') }}',
            },
        });

        $(document).on('click', '.consumption-open', function (e) {
            e.preventDefault();
            var ref = $(this).data('ref');
            var title = $(this).data('title');
            $('#consumption-detail-title').text(title);
            $('#consumption-detail-wrap').addClass('d-none');
            $('#consumption-detail-loading').show();
            $('#consumption-detail-body').empty();
            $('#modalConsumptionDetail').modal('show');

            $.getJSON(consumption_url.detail, { ref: ref }, function (resp) {
                $('#consumption-detail-loading').hide();
                $('#consumption-detail-wrap').removeClass('d-none');
                var $body = $('#consumption-detail-body');
                (resp.items || []).forEach(function (it) {
                    var cls = it.value < 0 ? 'text-danger' : 'text-success';
                    var sign = it.value >= 0 ? '+' : '';
                    var tr = $('<tr>');
                    tr.append('<td>' + escapeHtml(it.character_name) + '</td>');
                    tr.append('<td class="text-right ' + cls + '">' + sign + Number(it.value).toFixed(2) + '</td>');
                    tr.append('<td>' + escapeHtml(it.reason || '') + '</td>');
                    tr.append('<td>' + escapeHtml(it.at || '') + '</td>');
                    $body.append(tr);
                });
            });
        });
    </script>
@endpush
