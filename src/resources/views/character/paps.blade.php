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
                    <div class="info-box bg-info">
                        <span class="info-box-icon"><i class="fas fa-calendar"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('calendar::paps.this_month_paps') }}</span>
                            <span class="info-box-number">{{ $thisMonthPaps }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-box bg-success">
                        <span class="info-box-icon"><i class="fas fa-chart-bar"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('calendar::paps.this_year_paps') }}</span>
                            <span class="info-box-number">{{ $thisYearPaps }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <h4>{{ trans('calendar::paps.my_paps_per_month') }}</h4>
            <div class="chart">
                <canvas id="papPerMonth" height="150" width="1000"></canvas>
            </div>
            <h4>{{ trans('calendar::paps.hall_of_fame_header') }}</h4>
            <div class="row">
                <div class="col-md-4">
                    <h5>{{ trans('calendar::paps.this_week_header') }}</h5>
                    @include('calendar::common.includes.ranking_table', [
                        'ranking' => $weeklyRanking,
                        'emptyMessage' => trans('calendar::paps.first_week_paps'),
                        'highlightId' => $mainCharacterId,
                    ])
                </div>
                <div class="col-md-4">
                    <h5>{{ trans('calendar::paps.this_month_header') }}</h5>
                    @include('calendar::common.includes.ranking_table', [
                        'ranking' => $monthlyRanking,
                        'emptyMessage' => trans('calendar::paps.first_month_paps'),
                        'highlightId' => $mainCharacterId,
                    ])
                </div>
                <div class="col-md-4">
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
            monthlyData.push({x: "{{ $pap->year }}-{{ $pap->month }}", y: {{ $pap->qty }}});
            @endforeach

            new Chart(document.getElementById('papPerMonth').getContext('2d'), {
                type: 'line',
                data: {
                    datasets: [{
                        label: '# participation',
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
                        yAxes: [{ticks: {min: 0, stepSize: 1}}]
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
