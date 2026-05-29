@extends('web::layouts.grids.12')

@section('title', trans('calendar::seat.plugin_name') . ' | ' . trans('calendar::seat.audit'))
@section('page_header', trans('calendar::seat.audit'))

@section('full')

    @include('calendar::audit.modals.audit_members')
    @include('calendar::audit.modals.adjust_pap')

    <div class="card">
        <div class="card-body">
            <table id="audit-operations" class="table table-hover" style="width:100%">
                <thead>
                    <tr>
                        <th>{{ trans('calendar::paps.audit_col_op_title') }}</th>
                        <th>{{ trans('calendar::paps.audit_col_fleet_end') }}</th>
                        <th>{{ trans('calendar::paps.audit_col_fc') }}</th>
                        <th class="text-right">{{ trans('calendar::paps.audit_col_pap_value') }}</th>
                        <th class="text-right">{{ trans('calendar::paps.audit_col_member_count') }}</th>
                        <th class="text-right">{{ trans('calendar::paps.audit_col_total') }}</th>
                        <th class="text-center">{{ trans('calendar::paps.audit_col_actions') }}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>

@stop

@push('head')
    <link rel="stylesheet" href="{{ asset('web/css/calendar.css') }}"/>
    <style>
        #audit-members-table .audit-history { min-width: 200px; max-width: 320px; }
        #audit-members-table .audit-history-item { font-size: 0.88rem; line-height: 1.35; padding: 1px 0; }
        #audit-members-table .audit-history-item strong { display: inline-block; min-width: 3.2em; }
    </style>
@endpush

@push('javascript')
    <script>
        var audit_url = {
            operations: '{{ route('audit.operations.json') }}',
            members_template: '{{ route('operation.audit.members', ['id' => 0]) }}',
            adjust_template: '{{ route('operation.audit.adjust', ['id' => 0]) }}',
            zero_template: '{{ route('operation.audit.zero', ['id' => 0]) }}',
            lottery_show_template: '{{ route('lottery.show', ['lottery' => 0]) }}',
        };

        var audit_state = {
            current_op_id: null,
            current_op_title: null,
            current_pap_type: null,
            current_member: null,    // {character_id, name}
            current_direction: null, // 'award' | 'deduct'
        };

        function escapeHtml(str) {
            return $('<div>').text(str == null ? '' : String(str)).html();
        }

        function formatHistory(items) {
            if (!items || items.length === 0) return '<span class="text-muted">—</span>';
            return items.map(function (a) {
                var sign = a.value >= 0 ? '+' : '';
                var cls = a.value >= 0 ? 'text-success' : 'text-danger';
                var tip = (a.by_name || '') + ' · ' + (a.at || '');
                return '<div class="audit-history-item ' + cls + '" data-toggle="tooltip" title="' +
                    escapeHtml(tip) + '"><strong>' + sign + a.value.toFixed(2) +
                    '</strong> ' + escapeHtml(a.reason || '') + '</div>';
            }).join('');
        }

        function renderMembers(payload) {
            var op = payload.operation;
            $('#audit-fleet-end').text(op.fleet_end_at || '—');
            $('#audit-pap-type').text(op.analytics || '—');
            $('#audit-pap-value').text(op.pap_value.toFixed(2));
            $('#audit-member-count').text(op.member_count);
            $('#audit-total').text(op.pap_total.toFixed(2));
            audit_state.current_pap_type = op.analytics;

            // 抽奖行动：在弹窗显示跳转抽奖详情页的链接（§4.4.3）
            if (op.is_lottery && op.lottery_id) {
                $('#audit-lottery-link')
                    .attr('href', audit_url.lottery_show_template.replace(/\/0$/, '/' + op.lottery_id))
                    .removeClass('d-none');
            } else {
                $('#audit-lottery-link').addClass('d-none');
            }

            var showActions = !!payload.can_audit;
            $('#audit-zero-btn').toggleClass('d-none', !showActions);

            // 销毁已存在的 DataTable，准备重建
            var $table = $('#audit-members-table');
            if ($.fn.DataTable.isDataTable($table)) {
                $table.DataTable().destroy();
            }

            var $tbody = $table.find('tbody').empty();
            payload.members.forEach(function (m) {
                var ship = escapeHtml(m.ship_name) +
                    ' <small class="text-muted">#' + m.ship_type_id + '</small>';
                var sys = m.solar_system_name
                    ? escapeHtml(m.solar_system_name)
                    : '<span class="text-muted">—</span>';
                // join_time 用 data-order 兜底（NULL 时让 DataTables 排到最后）
                var joinOrder = m.join_time || '';
                var tr = $('<tr>').attr('data-character-id', m.character_id);
                tr.append('<td><img src="https://images.evetech.net/characters/' + m.character_id + '/portrait?size=32" ' +
                    'class="img-circle" style="height:24px;width:24px"> ' + escapeHtml(m.character_name) + '</td>');
                tr.append('<td>' + ship + '</td>');
                tr.append('<td>' + sys + '</td>');
                tr.append('<td data-order="' + escapeHtml(joinOrder) + '">' + escapeHtml(m.join_time || '—') + '</td>');
                tr.append('<td class="text-right pap-value" data-order="' + m.value + '">' + m.value.toFixed(2) + '</td>');
                tr.append('<td class="audit-history">' + formatHistory(m.adjustments) + '</td>');
                if (showActions) {
                    var actions =
                        '<button type="button" class="btn btn-xs btn-success btn-adjust" ' +
                        'data-direction="award" data-character-id="' + m.character_id + '" ' +
                        'data-character-name="' + escapeHtml(m.character_name) + '">' +
                        '<i class="fas fa-plus"></i></button> ' +
                        '<button type="button" class="btn btn-xs btn-danger btn-adjust" ' +
                        'data-direction="deduct" data-character-id="' + m.character_id + '" ' +
                        'data-character-name="' + escapeHtml(m.character_name) + '">' +
                        '<i class="fas fa-minus"></i></button>';
                    tr.append('<td class="text-center">' + actions + '</td>');
                } else {
                    // 始终保持 7 列结构，DataTables 用 visible:false 隐藏整列
                    tr.append('<td></td>');
                }
                $tbody.append(tr);
            });

            // 初始化 DataTable：默认按加入时间倒序，奖惩历史 / 操作不排序
            $table.DataTable({
                paging: false,
                info: false,
                searching: false,
                order: [[3, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [5, 6] },
                    { visible: showActions, targets: 6 },
                ],
                language: {
                    emptyTable: '{{ trans('calendar::paps.no_data') }}',
                },
            });

            $tbody.find('[data-toggle="tooltip"]').tooltip();
        }

        function loadAuditMembers(opId, opTitle) {
            audit_state.current_op_id = opId;
            audit_state.current_op_title = opTitle;

            $('#audit-op-title').text(opTitle);
            $('#audit-loading').show();
            $('#audit-error, #audit-content').addClass('d-none');
            $('#audit-zero-btn').addClass('d-none').prop('disabled', false);

            $.ajax({
                url: audit_url.members_template.replace('/operation/0/', '/operation/' + opId + '/'),
                method: 'GET',
                dataType: 'json',
                success: function (payload) {
                    $('#audit-loading').hide();
                    $('#audit-content').removeClass('d-none');
                    renderMembers(payload);
                },
                error: function (xhr) {
                    $('#audit-loading').hide();
                    $('#audit-error-msg').text((xhr.responseJSON && xhr.responseJSON.message) || 'Error');
                    $('#audit-error').removeClass('d-none');
                }
            });
        }

        $('#audit-operations').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: audit_url.operations,
            },
            order: [[1, 'desc']],
            columns: [
                {data: 'title', name: 'title',
                    render: function (d, t, row) {
                        var title = escapeHtml(d || '');
                        if (row.is_lottery) {
                            return '<span class="badge badge-warning mr-1">' +
                                '{{ trans('calendar::paps.audit_lottery_badge') }}</span>' + title;
                        }
                        return title;
                    }
                },
                {data: 'fleet_end_at', name: 'fleet_end_at',
                    render: function (d) { return d || '<span class="text-muted">—</span>'; }
                },
                {data: 'fc', name: 'fc', orderable: false,
                    render: function (d, t, row) {
                        if (!row.fc_character_id) return escapeHtml(d || '—');
                        return '<img src="https://images.evetech.net/characters/' + row.fc_character_id +
                            '/portrait?size=32" class="img-circle" style="height:24px;width:24px"> ' +
                            escapeHtml(d || '');
                    }
                },
                {data: 'pap_value', name: 'pap_value', className: 'text-right',
                    render: function (d, t, row) {
                        // 抽奖行动单 PAP 显示 — 而非 0，避免和真实 PAP 混淆（§4.4.3）
                        if (row.is_lottery) return '<span class="text-muted">—</span>';
                        return Number(d).toFixed(2);
                    }
                },
                {data: 'member_count', name: 'member_count', className: 'text-right'},
                {data: 'pap_total', name: 'pap_total', className: 'text-right',
                    render: function (d, t, row) {
                        var v = Number(d);
                        // 抽奖总额为负时显示“消费 PAP xxx”（§4.4.3）
                        if (row.is_lottery && v < 0) {
                            return '{{ trans('calendar::paps.audit_lottery_spent', ['amount' => ':A']) }}'
                                .replace(':A', Math.abs(v).toFixed(2));
                        }
                        return v.toFixed(2);
                    }
                },
                {data: null, orderable: false, searchable: false, className: 'text-center',
                    render: function (d, t, row) {
                        if (row.can_audit) {
                            return '<button type="button" class="btn btn-xs btn-info btn-audit-open" ' +
                                'data-op-id="' + row.id + '" data-op-title="' + escapeHtml(row.title) + '">' +
                                '<i class="fas fa-search"></i> ' +
                                '{{ trans('calendar::paps.audit_btn_open') }}</button>';
                        }
                        return '<span class="text-muted">{{ trans('calendar::paps.audit_no_permission_hint') }}</span>';
                    }
                },
            ],
        });

        // 打开成员明细 modal
        $(document).on('click', '.btn-audit-open', function () {
            var opId = $(this).data('op-id');
            var opTitle = $(this).data('op-title');
            $('#modalAuditMembers').modal('show');
            loadAuditMembers(opId, opTitle);
        });

        // 整行动 PAP 清零
        $('#audit-zero-btn').on('click', function () {
            if (!audit_state.current_op_id) return;
            if (!confirm('{{ trans('calendar::paps.audit_zero_confirm') }}')) {
                return;
            }

            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: audit_url.zero_template.replace('/operation/0/', '/operation/' + audit_state.current_op_id + '/'),
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function (resp) {
                    alert(resp.message || '{{ trans('calendar::paps.audit_zero_no_changes') }}');
                    loadAuditMembers(audit_state.current_op_id, audit_state.current_op_title);
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message)
                        || 'Error';
                    alert(msg);
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        });

        // 打开奖惩 modal
        $(document).on('click', '.btn-adjust', function () {
            audit_state.current_direction = $(this).data('direction');
            audit_state.current_member = {
                character_id: $(this).data('character-id'),
                name: $(this).data('character-name'),
            };

            var isAward = audit_state.current_direction === 'award';
            $('#adjust-header')
                .removeClass('bg-success bg-danger')
                .addClass(isAward ? 'bg-success' : 'bg-danger');
            $('#adjust-direction-icon').html(isAward
                ? '<i class="fas fa-plus"></i>'
                : '<i class="fas fa-minus"></i>');
            $('#adjust-title').text(
                (isAward
                    ? '{{ trans('calendar::paps.audit_award_title') }}'
                    : '{{ trans('calendar::paps.audit_deduct_title') }}')
                + ' — ' + audit_state.current_op_title
            );
            $('#adjust-member-name').text(audit_state.current_member.name);
            $('#adjust-pap-type').text(audit_state.current_pap_type || '—');
            $('#adjust-value').val('');
            $('#adjust-reason').val('');
            $('#adjust-error').addClass('d-none').empty();
            $('#adjust-submit-btn')
                .removeClass('btn-success btn-danger')
                .addClass(isAward ? 'btn-success' : 'btn-danger')
                .html((isAward ? '<i class="fas fa-check"></i> ' : '<i class="fas fa-check"></i> ')
                    + (isAward
                        ? '{{ trans('calendar::paps.audit_award_btn') }}'
                        : '{{ trans('calendar::paps.audit_deduct_btn') }}'));

            $('#modalAdjustPap').modal('show');
        });

        // 提交奖惩
        $('#adjust-submit-btn').on('click', function () {
            var value = parseFloat($('#adjust-value').val());
            var reason = $('#adjust-reason').val().trim();
            if (!(value > 0)) {
                $('#adjust-error').removeClass('d-none').text('{{ trans('calendar::paps.audit_invalid_amount') }}');
                return;
            }
            if (reason === '') {
                $('#adjust-error').removeClass('d-none').text('{{ trans('calendar::paps.audit_invalid_reason') }}');
                return;
            }

            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: audit_url.adjust_template.replace('/operation/0/', '/operation/' + audit_state.current_op_id + '/'),
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    character_id: audit_state.current_member.character_id,
                    value: value,
                    direction: audit_state.current_direction,
                    reason: reason,
                },
                success: function (resp) {
                    var charId = audit_state.current_member.character_id;
                    var $row = $('#audit-members-table tbody tr[data-character-id="' + charId + '"]');
                    var $papCell = $row.find('.pap-value');
                    $papCell.text(Number(resp.new_value).toFixed(2));
                    $papCell.attr('data-order', resp.new_value);

                    var $hist = $row.find('.audit-history');
                    if ($hist.find('.audit-history-item').length === 0) {
                        $hist.empty();
                    }
                    var sign = resp.adjustment.value >= 0 ? '+' : '';
                    var cls = resp.adjustment.value >= 0 ? 'text-success' : 'text-danger';
                    var tip = (resp.adjustment.by_name || '') + ' · ' + (resp.adjustment.at || '');
                    var $item = $('<div class="audit-history-item ' + cls + '" data-toggle="tooltip">')
                        .attr('title', tip)
                        .html('<strong>' + sign + resp.adjustment.value.toFixed(2) + '</strong> ');
                    $item.append(document.createTextNode(resp.adjustment.reason || ''));
                    $hist.append($item);
                    $hist.find('[data-toggle="tooltip"]').tooltip();

                    // 通知 DataTables 重新读取该行 DOM 数据，保持当前排序与列宽
                    var dt = $('#audit-members-table').DataTable();
                    dt.row($row[0]).invalidate('dom').draw(false);

                    var $total = $('#audit-total');
                    $total.text((parseFloat($total.text()) + resp.adjustment.value).toFixed(2));

                    $('#modalAdjustPap').modal('hide');
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message)
                        || (xhr.responseJSON && xhr.responseJSON.errors
                            && Object.values(xhr.responseJSON.errors).flat().join('; '))
                        || 'Error';
                    $('#adjust-error').removeClass('d-none').text(msg);
                },
                complete: function () { $btn.prop('disabled', false); }
            });
        });
    </script>
@endpush
