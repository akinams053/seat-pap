{{-- 出勤 / 消费 / 入账（当期净额）展示（见计划 §11）。$data = ['attendance'=>, 'consumed'=>, 'available'=>]
     第三列是「当期净额 = 出勤 - 消费」，按月/按年是区间净额、可能为负，故称「入账 PAP」而非「可用」
     （真正的当前可用余额是累计 SUM(value)，不是单个区间的值） --}}
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
        <div class="text-muted small">{{ trans('calendar::paps.net_pap') }}</div>
        <div class="h5 mb-0">{{ number_format($data['available'], 2) }}</div>
    </div>
</div>
