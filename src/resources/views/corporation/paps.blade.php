@extends('web::corporation.layouts.view', ['viewname' => 'paps'])

@section('title', trans_choice('web::seat.corporation', 1) . ' ' . trans('calendar::seat.paps'))
@section('page_header', trans_choice('web::seat.corporation', 1) . ' ' . trans('calendar::seat.paps'))

@inject('request', 'Illuminate\Http\Request')

@section('corporation_content')

    {{-- Overview --}}
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('calendar::paps.monthly_trend_header') }} ({{ carbon()->year }})</h3>
                </div>
                <div class="card-body">
                    <canvas id="monthlyTrendChart" height="60"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('calendar::paps.type_distribution_header') }} ({{ trans('calendar::paps.this_month_header') }})</h3>
                </div>
                <div class="card-body">
                    <canvas id="monthTypeDistChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('calendar::paps.type_distribution_header') }} ({{ trans('calendar::paps.this_year_header') }})</h3>
                </div>
                <div class="card-body">
                    <canvas id="yearTypeDistChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Detailed stats --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                {{ trans('calendar::paps.stats_header') }}
                <small class="text-muted ml-2">{{ trans('calendar::paps.main_character_grouped') }}</small>
            </h3>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-sm-3">
                    <div class="input-group input-group-sm" id="yearChartSettings">
                        <input type="text" name="year" class="form-control" value="{{ carbon()->year }}"
                               placeholder="year"/>
                        <span class="input-group-append">
                            <button type="button"
                                    class="btn btn-info btn-flat">{{ trans('calendar::paps.display_btn') }}</button>
                        </span>
                    </div>
                </div>
                <div class="col-12">
                    <div class="chart">
                        <canvas id="yearPaps" height="600" width="1200"></canvas>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-4">
                    <div class="input-group input-group-sm" id="monthlyStackedChartSettings">
                        <select name="month" class="form-control">
                            @for($i = 1; $i < 13; $i++)
                                <option value="{{ $i }}"
                                        @if($i == carbon()->month)selected="selected"@endif>{{ $i }}</option>
                            @endfor
                        </select>
                        <input type="text" name="year" class="form-control" value="{{ carbon()->year }}"
                               placeholder="year"/>
                        <span class="input-group-append">
                            <button type="button"
                                    class="btn btn-info btn-flat">{{ trans('calendar::paps.display_btn') }}</button>
                        </span>
                    </div>
                </div>
                <div class="col-12">
                    <div class="chart">
                        <canvas id="monthlyStackedChart" width="1200"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Rankings --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                {{ trans('calendar::paps.ranking_header') }}
                <small class="text-muted ml-2">{{ trans('calendar::paps.main_character_grouped') }}</small>
            </h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h4>{{ trans('calendar::paps.this_week_header') }}</h4>
                    @include('calendar::common.includes.ranking_table', [
                        'ranking' => $weeklyRanking,
                        'emptyMessage' => trans('calendar::paps.no_paps_this_week'),
                    ])
                </div>
                <div class="col-md-4">
                    <h4>{{ trans('calendar::paps.this_month_header') }}</h4>
                    @include('calendar::common.includes.ranking_table', [
                        'ranking' => $monthlyRanking,
                        'emptyMessage' => trans('calendar::paps.no_paps_this_month'),
                    ])
                </div>
                <div class="col-md-4">
                    <h4>{{ trans('calendar::paps.this_year_header') }}</h4>
                    @include('calendar::common.includes.ranking_table', [
                        'ranking' => $yearlyRanking,
                        'emptyMessage' => trans('calendar::paps.no_paps_this_year'),
                    ])
                </div>
            </div>
        </div>
    </div>
@stop

@push('javascript')
    <script type="text/javascript" src="{{ asset('web/js/rainbowvis.js') }}"></script>
    <script type="text/javascript">
        $(function () {
            let yearChart, monthChart;
            let rainbow = new Rainbow();
            let yearChartParameters = $('#yearChartSettings');
            let monthChartParameters = $('#monthlyStackedChartSettings');
            let themeColor = rgb2hex($('.nav-pills .nav-link.active').css('backgroundColor'));
            let defaultColors = ['#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997'];

            if (themeColor.substr(4) === rgb2hex($('.card').css('backgroundColor')).substr(4))
                themeColor = '#000000';

            // --- Monthly trend chart ---
            let trendData = {!! json_encode($monthlyTrend) !!};
            let monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            let trendValues = new Array(12).fill(0);
            trendData.forEach(function (item) {
                trendValues[item.month - 1] = parseFloat(item.qty);
            });

            new Chart(document.getElementById('monthlyTrendChart').getContext('2d'), {
                type: 'line',
                data: {
                    labels: monthNames,
                    datasets: [{
                        label: 'PAPs',
                        data: trendValues,
                        borderColor: themeColor,
                        fill: true,
                        backgroundColor: themeColor + '22',
                        pointRadius: 4,
                        pointBackgroundColor: themeColor
                    }]
                },
                options: {
                    legend: {display: false},
                    scales: {
                        yAxes: [{ticks: {min: 0, stepSize: 1}}]
                    }
                }
            });

            // --- Type distribution charts ---
            function renderPieChart(canvasId, data) {
                if (data.length > 0) {
                    new Chart(document.getElementById(canvasId).getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: data.map(function (t) { return t.analytics || 'Unknown'; }),
                            datasets: [{
                                data: data.map(function (t) { return parseFloat(t.qty); }),
                                backgroundColor: data.map(function (t, i) { return t.bg_color || defaultColors[i % defaultColors.length]; })
                            }]
                        },
                        options: {
                            legend: {position: 'bottom'}
                        }
                    });
                }
            }

            renderPieChart('monthTypeDistChart', {!! json_encode($monthTypeDistribution) !!});
            renderPieChart('yearTypeDistChart', {!! json_encode($yearTypeDistribution) !!});

            // --- Year chart (always grouped by main character) ---
            let yearChartSettings = {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        type: 'line',
                        label: '% participation',
                        data: [],
                        yAxisID: 'pareto',
                        fill: false
                    }, {
                        type: 'bar',
                        label: '# participation',
                        data: [],
                        backgroundColor: [],
                        yAxisID: 'quantity'
                    }]
                },
                options: {
                    responsive: true,
                    title: {display: true, text: 'participation of year'},
                    tooltips: {mode: 'index', intersect: true},
                    scales: {
                        xAxes: [{barThickness: 20}],
                        yAxes: [{
                            id: 'quantity',
                            ticks: {min: 0, stepSize: 1},
                            position: 'left'
                        }, {
                            id: 'pareto',
                            ticks: {min: 0},
                            gridLines: {drawOnChartArea: false},
                            position: 'right'
                        }]
                    }
                }
            };

            let monthChartSettings = {
                type: 'horizontalBar',
                data: {labels: [], datasets: []},
                options: {
                    title: {display: true, text: 'stacked participation of the month'},
                    scales: {
                        xAxes: [{stacked: true}],
                        yAxes: [{stacked: true, barThickness: 20}]
                    }
                }
            };

            yearChartParameters.find('button').on('click', function () {
                $.ajax({
                    url: '{{ route('corporation.ajax.paps.year', request()->route('corporation')) }}',
                    data: {
                        year: yearChartParameters.find('input[name="year"]').val(),
                        grouped: 1
                    },
                    success: function (data) {
                        let pareto = [];

                        if (typeof yearChart !== 'undefined')
                            yearChart.destroy();

                        yearChartSettings.data.labels = [];
                        yearChartSettings.data.datasets[0].data = [];
                        yearChartSettings.data.datasets[1].data = [];
                        yearChartSettings.data.datasets[1].backgroundColor = [];
                        $('#yearPaps').parent('.chart').find('p').remove();

                        if (data.length < 1) {
                            $('#yearPaps').parent('.chart')
                                .append('<p class="text-danger text-center">There are no data to display</p>');
                            return;
                        }

                        rainbow.setNumberRange(0, data.length);
                        rainbow.setSpectrum('#8e8e8e', themeColor, '#dddddd');

                        $(data).each(function (index, record) {
                            yearChartSettings.data.labels.push((record.name == null) ? 'Unknown' : record.name);
                            yearChartSettings.data.datasets[1].data.push(record.qty);

                            if (pareto.length > 0)
                                pareto.push(pareto[pareto.length - 1] + parseFloat(record.qty));
                            else
                                pareto.push(parseFloat(record.qty));

                            yearChartSettings.data.datasets[1].backgroundColor.push('#' + rainbow.colourAt(index));
                        });

                        $(pareto).each(function (index, value) {
                            yearChartSettings.data.datasets[0].data.push(value / pareto[pareto.length - 1] * 100);
                        });

                        yearChartSettings.options.title.text = 'grouped participation of year ' +
                            yearChartParameters.find('input[name="year"]').val();

                        yearChart = new Chart(document.getElementById('yearPaps').getContext('2d'), yearChartSettings);
                    }
                });
            });

            monthChartParameters.find('button').on('click', function () {
                $.ajax({
                    url: '{{ route('corporation.ajax.paps.stacked', request()->route('corporation')) }}',
                    data: {
                        year: monthChartParameters.find('input[name="year"]').val(),
                        month: monthChartParameters.find('select[name="month"]').val(),
                        grouped: 1
                    },
                    success: function (data) {
                        let pointFound = false;
                        let seriesFound = false;
                        let datasetLabels = [];
                        let series = [];

                        if (typeof (monthChart) !== 'undefined')
                            monthChart.destroy();

                        monthChartSettings.data.labels = [];
                        monthChartSettings.data.datasets = [];
                        $('#monthlyStackedChart').parent('.chart').find('p').remove();

                        if (data.length < 1) {
                            $('#monthlyStackedChart').parent('.chart')
                                .append('<p class="text-danger text-center">There are no data to display</p>');
                            return;
                        }

                        $(data).each(function (index, record) {
                            pointFound = false;
                            seriesFound = false;

                            if ($.inArray(record.name, monthChartSettings.data.labels) < 0)
                                monthChartSettings.data.labels.push(record.name);

                            if ($.inArray(record.analytics, datasetLabels) < 0) {
                                datasetLabels.push(record.analytics);
                                monthChartSettings.data.datasets.push({label: record.analytics, data: []});
                            }

                            $(series).each(function (index, serie) {
                                if (serie.label === record.name) {
                                    seriesFound = true;
                                    $(serie.points).each(function (index, point) {
                                        if (point.name === record.analytics) {
                                            pointFound = true;
                                            point.value += parseFloat(record.qty);
                                        }
                                    });
                                    if (!pointFound)
                                        serie.points.push({name: record.analytics, value: parseFloat(record.qty)});
                                }
                            });

                            if (!seriesFound)
                                series.push({
                                    label: record.name,
                                    points: [{name: record.analytics, value: record.qty}]
                                });
                        });

                        rainbow.setNumberRange(0, monthChartSettings.data.datasets.length);
                        rainbow.setSpectrum(themeColor, '#dddddd');

                        $(monthChartSettings.data.labels).each(function (labelIndex) {
                            pointFound = false;
                            $(monthChartSettings.data.datasets).each(function (datasetIndex, dataset) {
                                dataset.backgroundColor = '#' + rainbow.colourAt(datasetIndex);
                                $(series[labelIndex].points).each(function (pointIndex, point) {
                                    if (point.name === dataset.label) {
                                        pointFound = true;
                                        dataset.data.push(parseFloat(point.value));
                                    }
                                });
                                if (!pointFound)
                                    dataset.data.push(0.0);
                            });
                        });

                        monthChartSettings.options.title.text = 'grouped participation of ' +
                            monthChartParameters.find('select[name="month"]').val() + '-' +
                            monthChartParameters.find('input[name="year"]').val();

                        monthChart = new Chart(document.getElementById('monthlyStackedChart').getContext('2d'), monthChartSettings);
                    }
                });
            });

            yearChartParameters.find('button').click();
            monthChartParameters.find('button').click();

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
