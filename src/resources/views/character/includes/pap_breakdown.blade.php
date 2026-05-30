{{-- 出勤 / 消费 / 当前可用三口径展示（见计划 §11）。$data = ['attendance'=>, 'consumed'=>, 'available'=>] --}}
<div class="row text-center">
    <div class="col-4">
        <div class="text-muted small">{{ trans('calendar::paps.attendance_pap') }}</div>
        <div class="h5 mb-0">{{ number_format($data['attendance'], 2) }}</div>
    </div>
    <div class="col-4 border-left border-right">
        <div class="text-muted small">{{ trans('calendar::paps.consumed_pap') }}</div>
        <div class="h5 mb-0">{{ number_format($data['consumed'], 2) }}</div>
    </div>
    <div class="col-4">
        <div class="text-muted small">{{ trans('calendar::paps.available_pap') }}</div>
        <div class="h5 mb-0">{{ number_format($data['available'], 2) }}</div>
    </div>
</div>
