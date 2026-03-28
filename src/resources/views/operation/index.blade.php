@extends('web::layouts.grids.12')

@section('title', trans('calendar::seat.plugin_name') . ' | ' . trans('calendar::seat.operations'))
@section('page_header', trans('calendar::seat.all_operations'))

@section('full')

    @if(auth()->user()->can('calendar.create'))
        <div class="row margin-bottom">
            <div class="col-md-offset-8 col-md-4">
                <div class="pull-right">
                    <button type="button" class="btn btn-primary" data-toggle="modal"
                            data-target="#modalCreateOperation">
                        <i class="fas fa-plus"></i>&nbsp;&nbsp;
                        {{ trans('calendar::seat.add_operation') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    @include('calendar::operation.modals.create_operation')
    @include('calendar::operation.modals.update_operation')
    @include('calendar::operation.modals.confirm_delete')
    @include('calendar::operation.modals.confirm_close')
    @include('calendar::operation.modals.confirm_cancel')
    @include('calendar::operation.modals.confirm_activate')
    @include('calendar::operation.modals.subscribe')
    @include('calendar::operation.modals.details')
    @include('calendar::operation.modals.confirm_pap')

    <div class="row">
        <div class="col-md-12">
            @include('calendar::operation.ongoing')
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            @include('calendar::operation.incoming')
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            @include('calendar::operation.faded')
        </div>
    </div>

@stop

@push('head')
    <link rel="stylesheet" href="{{ asset('web/css/daterangepicker.css') }}"/>
    <link rel="stylesheet" href="{{ asset('web/css/bootstrap-slider.min.css') }}"/>
    <link rel="stylesheet" href="{{ asset('web/css/calendar.css') }}"/>
@endpush

@push('javascript')
    <script>
        var seat_calendar = {
            url: {
                create_operation: '{{ route('operation.store') }}',
                update_operation: '{{ route('operation.update') }}',
                characters_lookup: '{{ route('calendar.lookups.characters') }}',
                systems_lookup: '{{ route('calendar.lookups.systems') }}'
            }
        };
    </script>

    <script src="{{ asset('web/js/daterangepicker.js') }}"></script>
    <script src="{{ asset('web/js/bootstrap-slider.min.js') }}"></script>
    <script src="{{ asset('web/js/jquery.autocomplete.min.js') }}"></script>
    <script src="{{ asset('web/js/natural.js') }}"></script>
    <script src="{{ asset('web/js/calendar.js') }}"></script>
    @include('web::includes.javascript.id-to-name')
    <script type="text/javascript">
        $('#calendar-ongoing').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route('operation.ongoing') }}'
            },
            dom: 'rt<"col-sm-5"i><"col-sm-7"p>',
            columns: [
                {data: 'title', name: 'title'},
                {data: 'tags', name: 'tags', orderable: false},
                {data: 'importance', name: 'importance'},
                {data: 'start_at', name: 'start_at'},
                {data: 'end_at', name: 'end_at'},
                {data: 'fleet_commander', name: 'fleet_commander', orderable: false},
                {data: 'staging_sys', name: 'staging_sys'},
                {data: 'subscription', name: 'subscription', orderable: false},
                {data: 'actions', name: 'actions', orderable: false, searchable: false}
            ],
            order: [
                [4, 'desc']
            ],
            drawCallback: function () {
                // enable tooltip
                $('[data-toggle="tooltip"]').tooltip();

                // resolve EVE ids to names.
                ids_to_names();
            }
        });

        $('#calendar-incoming').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route('operation.incoming') }}'
            },
            dom: 'rt<"col-sm-5"i><"col-sm-7"p>',
            columns: [
                {data: 'title', name: 'title'},
                {data: 'tags', name: 'tags', orderable: false},
                {data: 'importance', name: 'importance'},
                {data: 'start_at', name: 'start_at'},
                {data: 'duration', name: 'duration', orderable: false},
                {data: 'fleet_commander', name: 'fleet_commander', orderable: false},
                {data: 'staging_sys', name: 'staging_sys'},
                {data: 'subscription', name: 'subscription', orderable: false},
                {data: 'actions', name: 'actions', orderable: false, searchable: false}
            ],
            order: [
                [3, 'asc']
            ],
            drawCallback: function () {
                // enable tooltip
                $('[data-toggle="tooltip"]').tooltip();

                // resolve EVE ids to names.
                ids_to_names();
            }
        });

        $('#calendar-faded').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route('operation.faded') }}'
            },
            dom: 'rt<"col-sm-5"i><"col-sm-7"p>',
            columns: [
                {data: 'title', name: 'title'},
                {data: 'tags', name: 'tags', orderable: false},
                {data: 'importance', name: 'importance'},
                {data: 'start_at', name: 'start_at'},
                {data: 'end_at', name: 'end_at'},
                {data: 'fleet_commander', name: 'fleet_commander', orderable: false},
                {data: 'staging_sys', name: 'staging_sys'},
                {data: 'subscription', name: 'subscription', orderable: false},
                {data: 'actions', name: 'actions', orderable: false, searchable: false}
            ],
            order: [
                [4, 'desc']
            ],
            drawCallback: function () {
                // enable tooltip
                $('[data-toggle="tooltip"]').tooltip();

                // resolve EVE ids to names.
                ids_to_names();
            }
        });

        $('#modalDetails')
            .on('show.bs.modal', function (e) {
                var link = '{{ route('operation.detail', 0) }}';

                // load detail content dynamically
                $(this).find('.modal-body')
                    .html('<div class="overlay dark d-flex justify-content-center align-items-center"><i class="fas fa-2x fa-sync-alt fa-spin"></i></div> Loading...')
                    .load(link.replace(/0$/gi, $(e.relatedTarget).attr('data-op-id')), "", function () {
                        // attach the datatable to the loaded modal
                        var attendees_table = $('#attendees');
                        var confirmed_table = $('#confirmed');

                        if (!$.fn.DataTable.isDataTable(attendees_table)) {
                            attendees_table.DataTable({
                                "ajax": "/calendar/lookup/attendees?id=" + $(e.relatedTarget).attr('data-op-id'),
                                "ordering": true,
                                "info": false,
                                "paging": true,
                                "processing": true,
                                "order": [[1, "asc"]],
                                "aoColumnDefs": [
                                    {orderable: false, targets: "no-sort"}
                                ],
                                "columns": [
                                    {data: '_character'},
                                    {data: '_status'},
                                    {data: '_comment'},
                                    {data: '_timestamps'}
                                ],
                                createdRow: function (row, data, dataIndex) {
                                    $(row).find('td:eq(0)').attr('data-order', data._character_name);
                                    $(row).find('td:eq(0)').attr('data-search', data._character_name);
                                }
                            });
                        }

                        if (!$.fn.DataTable.isDataTable(confirmed_table)) {
                            confirmed_table.DataTable({
                                "ajax": "/calendar/lookup/confirmed?id=" + $(e.relatedTarget).attr('data-op-id'),
                                "ordering": true,
                                "info": false,
                                "paging": true,
                                "processing": true,
                                "order": [[1, "asc"]],
                                "aoColumnsDefs": [
                                    {orderable: false, targets: "no-sort"}
                                ],
                                'fnDrawCallback': function () {
                                    $(document).ready(function () {
                                        ids_to_names();
                                    });
                                },
                                "columns": [
                                    {data: 'character.character_id'},
                                    {data: 'character.corporation_id'},
                                    {data: 'type.typeID'},
                                    {data: 'type.group.groupName'}
                                ],
                                createdRow: function (row, data, dataIndex) {
                                    $(row).find('td:eq(0)').attr('data-order', data.character.character_id);
                                    $(row).find('td:eq(0)').attr('data-search', data.character.character_id);
                                }
                            });
                        }
                    });
            })
            .on('hidden.bs.modal', function (e) {
                var table = $(this).find('#attendees').DataTable();
                table.destroy();

                table = $(this).find('#confirmed').DataTable();
                table.destroy();
            });

        // PAP 发放预览 + 确认
        $(document).on('click', '.btn-pap-issue', function () {
            var opId = $(this).data('op-id');
            var opTitle = $(this).data('op-title');
            var previewUrl = '/calendar/operation/' + opId + '/paps/preview';
            var confirmUrl = '/calendar/operation/' + opId + '/paps/confirm';

            // 重置弹窗状态
            $('#pap-loading').show();
            $('#pap-error, #pap-content, #pap-first-time, #pap-supplement, #pap-no-new').addClass('d-none');
            $('#pap-confirm-btn').addClass('d-none');
            $('#pap-new-list').empty();
            $('#pap-op-title').text(opTitle);
            $('#pap-confirm-form').attr('action', confirmUrl);

            $('#modalPapConfirm').modal('show');

            $.ajax({
                url: previewUrl,
                method: 'GET',
                dataType: 'json',
                success: function (data) {
                    $('#pap-loading').hide();
                    $('#pap-content').removeClass('d-none');

                    if (data.is_first_time) {
                        // 首次发放
                        $('#pap-fleet-count').text(data.total_in_fleet);
                        $('#pap-first-time').removeClass('d-none');
                        $('#pap-confirm-btn').removeClass('d-none');
                    } else if (data.new_count > 0) {
                        // 补发
                        $('#pap-new-count').text(data.new_count);
                        $('#pap-total-fleet').text(data.total_in_fleet);
                        $('#pap-issued-count').text(data.already_issued);
                        var list = $('#pap-new-list');
                        $.each(data.new_members, function (i, m) {
                            list.append('<li class="list-group-item py-1"><span data-character-id="' + m.character_id + '">' + m.character_id + '</span></li>');
                        });
                        $('#pap-supplement').removeClass('d-none');
                        $('#pap-confirm-btn').removeClass('d-none');
                        // 解析角色名
                        if (typeof ids_to_names === 'function') ids_to_names();
                    } else {
                        // 无新成员
                        $('#pap-no-new').removeClass('d-none');
                    }
                },
                error: function (xhr) {
                    $('#pap-loading').hide();
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Unknown error';
                    $('#pap-error-msg').text(msg);
                    $('#pap-error').removeClass('d-none');
                }
            });
        });

        // direct link
        @if(request()->route()->hasParameter('id'))
        var dl = $('<i>');
        dl.attr('data-op-id', {{ request()->route()->parameter('id') }});
        dl.attr('data-toggle', 'modal');
        dl.attr('data-target', '#modalDetails');

        $('body').find('.wrapper').append(dl);

        dl.click();

        dl.remove();
        @endif
    </script>
@endpush
