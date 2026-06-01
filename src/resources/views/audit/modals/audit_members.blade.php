<div class="modal fade" id="modalAuditMembers" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h4 class="modal-title">
                    <i class="fas fa-clipboard-check"></i>
                    <span id="audit-op-title"></span>
                    — {{ trans('calendar::paps.audit_modal_title') }}
                </h4>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="audit-loading" class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p class="mt-2">{{ trans('calendar::paps.audit_loading') }}</p>
                </div>

                <div id="audit-error" class="d-none">
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="audit-error-msg"></span>
                    </div>
                </div>

                <div id="audit-content" class="d-none">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <small class="text-muted">{{ trans('calendar::paps.audit_fleet_end') }}</small>
                            <div><strong id="audit-fleet-end">—</strong></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">{{ trans('calendar::paps.audit_pap_type') }}</small>
                            <div><strong id="audit-pap-type">—</strong></div>
                        </div>
                        <div class="col-md-2">
                            <small class="text-muted">{{ trans('calendar::paps.audit_pap_value') }}</small>
                            <div><strong id="audit-pap-value">—</strong></div>
                        </div>
                        <div class="col-md-2">
                            <small class="text-muted">{{ trans('calendar::paps.audit_member_count') }}</small>
                            <div><strong id="audit-member-count">—</strong></div>
                        </div>
                        <div class="col-md-2">
                            <small class="text-muted">{{ trans('calendar::paps.audit_total') }}</small>
                            <div><strong id="audit-total">—</strong></div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-condensed table-hover" id="audit-members-table">
                            <thead>
                                <tr>
                                    <th>{{ trans('calendar::paps.audit_col_member') }}</th>
                                    <th>{{ trans('calendar::paps.audit_col_ship') }}</th>
                                    <th>{{ trans('calendar::paps.audit_col_system') }}</th>
                                    <th>{{ trans('calendar::paps.audit_col_join_time') }}</th>
                                    <th class="text-right">{{ trans('calendar::paps.audit_col_pap') }}</th>
                                    <th>{{ trans('calendar::paps.audit_col_history') }}</th>
                                    <th class="text-center" id="audit-actions-th">{{ trans('calendar::paps.audit_col_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning d-none" id="audit-zero-btn">
                    <i class="fas fa-undo"></i> {{ trans('calendar::paps.audit_zero_btn') }}
                </button>
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('calendar::seat.close') }}</button>
            </div>
        </div>
    </div>
</div>
