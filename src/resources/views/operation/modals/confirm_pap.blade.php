<div class="modal fade" id="modalPapConfirm" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h4 class="modal-title">{{ trans('calendar::paps.pap_confirm_title') }}</h4>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="pap-loading" class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p class="mt-2">{{ trans('calendar::paps.pap_loading') }}</p>
                </div>
                <div id="pap-error" class="d-none">
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="pap-error-msg"></span>
                    </div>
                </div>
                <div id="pap-content" class="d-none">
                    <h5 id="pap-op-title" class="mb-3"></h5>

                    {{-- 首次发放 --}}
                    <div id="pap-first-time" class="d-none">
                        <div class="alert alert-info">
                            <i class="fas fa-users"></i>
                            {!! trans('calendar::paps.pap_first_time_msg', ['count' => '<strong id="pap-fleet-count"></strong>']) !!}
                        </div>
                        <p>{{ trans('calendar::paps.pap_first_time_hint') }}</p>
                    </div>

                    {{-- 补发 --}}
                    <div id="pap-supplement" class="d-none">
                        <div class="alert alert-warning">
                            <i class="fas fa-user-plus"></i>
                            {!! trans('calendar::paps.pap_supplement_msg', [
                                'new' => '<strong id="pap-new-count"></strong>',
                                'total' => '<strong id="pap-total-fleet"></strong>',
                                'issued' => '<strong id="pap-issued-count"></strong>',
                            ]) !!}
                        </div>
                        <div id="pap-new-list-wrap">
                            <p class="mb-1"><strong>{{ trans('calendar::paps.pap_new_members') }}</strong></p>
                            <ul id="pap-new-list" class="list-group list-group-flush" style="max-height: 200px; overflow-y: auto;"></ul>
                        </div>
                    </div>

                    {{-- 无新成员 --}}
                    <div id="pap-no-new" class="d-none">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            {{ trans('calendar::paps.pap_no_new_members') }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('calendar::seat.cancel') }}</button>
                <form id="pap-confirm-form" method="POST" action="" class="d-inline">
                    {{ csrf_field() }}
                    <button type="submit" id="pap-confirm-btn" class="btn btn-success d-none">
                        <i class="fas fa-check"></i> {{ trans('calendar::paps.pap_confirm_btn') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
