@extends('web::character.layouts.view', ['viewname' => 'paps'])

@section('title', trans_choice('web::seat.character', 1) . ' ' . trans('calendar::seat.paps'))
@section('page_header', trans_choice('web::seat.character', 1) . ' ' . trans('calendar::seat.paps'))

@section('character_content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ trans('calendar::seat.paps') }}</h3>
            <small class="text-muted ml-2">{{ trans('calendar::paps.main_character_grouped') }}</small>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card card-info mb-0">
                        <div class="card-header py-2">
                            <h3 class="card-title">
                                <i class="fas fa-calendar"></i> {{ trans('calendar::paps.this_month_paps') }}
                            </h3>
                        </div>
                        <div class="card-body p-3">
                            @include('calendar::character.includes.pap_breakdown', ['data' => $thisMonth])
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card card-success mb-0">
                        <div class="card-header py-2">
                            <h3 class="card-title">
                                <i class="fas fa-chart-bar"></i> {{ trans('calendar::paps.this_year_paps') }}
                            </h3>
                        </div>
                        <div class="card-body p-3">
                            @include('calendar::character.includes.pap_breakdown', ['data' => $thisYear])
                        </div>
                    </div>
                </div>
            </div>
            <h4>{{ trans('calendar::paps.attendance_pap_trend') }}</h4>
            <div class="chart">
                <canvas id="papPerMonth" height="150" width="1000"></canvas>
            </div>
            <h4>{{ trans('calendar::paps.hall_of_fame_header') }}
                <small class="text-muted ml-2">{{ trans('calendar::paps.attendance_pap') }}</small>
            </h4>
            <div class="row">
                <div class="col-md-6">
                    <h5>{{ trans('calendar::paps.this_month_header') }}</h5>
                    @include('calendar::common.includes.ranking_table', [
                        'ranking' => $monthlyRanking,
                        'emptyMessage' => trans('calendar::paps.first_month_paps'),
                        'highlightId' => $mainCharacterId,
                    ])
                </div>
                <div class="col-md-6">
                    <h5>{{ trans('calendar::paps.this_year_header') }}</h5>
                    @include('calendar::common.includes.ranking_table', [
                        'ranking' => $yearlyRanking,
                        'emptyMessage' => trans('calendar::paps.first_year_paps'),
                        'highlightId' => $mainCharacterId,
                    ])
                </div>
            </div>
        </div>
    </div>
@stop

@push('javascript')
    <script type="text/javascript">
        $(function () {
            let themeColor = rgb2hex($('.nav-pills .nav-link.active').css('backgroundColor'));
            let monthlyData = [];

            if (themeColor.substr(4) === rgb2hex($('.card').css('backgroundColor')).substr(4))
                themeColor = '#000000';

            @foreach($monthlyPaps as $pap)
            monthlyData.push({x: "{{ $pap->year }}-{{ $pap->month }}", y: {{ $pap->attendance }}});
            @endforeach

            new Chart(document.getElementById('papPerMonth').getContext('2d'), {
                type: 'line',
                data: {
                    datasets: [{
                        label: '{{ trans('calendar::paps.attendance_pap') }}',
                        data: monthlyData,
                        borderColor: themeColor
                    }]
                },
                options: {
                    legend: {display: false},
                    scales: {
                        xAxes: [{
                            type: 'time',
                            display: true,
                            time: {
                                unit: 'month',
                                displayFormats: {month: 'MMM YYYY'}
                            },
                            scaleLabel: {display: true, labelString: 'Timeline'}
                        }],
                        yAxes: [{ticks: {stepSize: 1}}]
                    }
                }
            });

            function rgb2hex(rgb) {
                try {
                    rgb = rgb.match(/^rgba?[\s+]?\([\s+]?(\d+)[\s+]?,[\s+]?(\d+)[\s+]?,[\s+]?(\d+)[\s+]?/i);
                    return (rgb && rgb.length === 4) ? "#" +
                        ("0" + parseInt(rgb[1], 10).toString(16)).slice(-2) +
                        ("0" + parseInt(rgb[2], 10).toString(16)).slice(-2) +
                        ("0" + parseInt(rgb[3], 10).toString(16)).slice(-2) : '';
                } catch (e) {
                    return rgb;
                }
            }
        });
    </script>
@endpush
