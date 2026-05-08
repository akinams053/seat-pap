<div class="modal fade" id="modalAdjustPap" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header" id="adjust-header">
                <h4 class="modal-title">
                    <span id="adjust-direction-icon"></span>
                    <span id="adjust-title"></span>
                </h4>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="adjust-error" class="alert alert-danger d-none"></div>

                <div class="form-group">
                    <label>{{ trans('calendar::paps.audit_target_member') }}</label>
                    <div><strong id="adjust-member-name"></strong></div>
                </div>
                <div class="form-group">
                    <label>{{ trans('calendar::paps.audit_pap_type') }}</label>
                    <div><span class="badge badge-secondary" id="adjust-pap-type"></span>
                        <small class="text-muted">{{ trans('calendar::paps.audit_type_inherit_hint') }}</small>
                    </div>
                </div>
                <div class="form-group">
                    <label for="adjust-value">{{ trans('calendar::paps.audit_amount') }} <span class="text-danger">*</span></label>
                    <input type="number" id="adjust-value" class="form-control" min="0.01" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="adjust-reason">{{ trans('calendar::paps.audit_reason') }} <span class="text-danger">*</span></label>
                    <textarea id="adjust-reason" class="form-control" rows="3" maxlength="255" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('calendar::seat.cancel') }}</button>
                <button type="button" id="adjust-submit-btn" class="btn"></button>
            </div>
        </div>
    </div>
</div>
