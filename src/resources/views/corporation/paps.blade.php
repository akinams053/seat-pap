@extends('web::corporation.layouts.view', ['viewname' => 'paps'])

@section('title', trans_choice('web::seat.corporation', 1) . ' ' . trans('calendar::seat.paps'))
@section('page_header', trans_choice('web::seat.corporation', 1) . ' ' . trans('calendar::seat.paps'))

@inject('request', 'Illuminate\Http\Request')

@section('corporation_content')

    {{-- Monthly Trend --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ trans('calendar::paps.monthly_trend_header') }}</h3>
            <div class="card-tools">
                <div class="input-group input-group-sm" style="width: 120px;" id="trendSettings">
                    <input type="text" name="year" class="form-control" value="{{ carbon()->year }}"/>
                    <span class="input-group-append">
                        <button type="button" class="btn btn-info btn-flat btn-sm">{{ trans('calendar::paps.display_btn') }}</button>
                    </span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <canvas id="monthlyTrendChart" height="60"></canvas>
            <p class="text-center text-muted d-none" id="trendEmpty">{{ trans('calendar::paps.no_data') }}</p>
        </div>
    </div>

    {{-- 出勤 PAP 类型分布（可选某月或全部月份=年度） + 军团消费 PAP --}}
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('calendar::paps.type_distribution_header') }}</h3>
                    <div class="card-tools">
                        <div class="input-group input-group-sm" style="width: 220px;" id="typeDistSettings">
                            <select name="month" class="form-control">
                                <option value="">-- {{ trans('calendar::paps.all_months') }} --</option>
                                @for($i = 1; $i <= 12; $i++)
                                    <option value="{{ $i }}" @if($i == carbon()->month) selected @endif>{{ $i }}</option>
                                @endfor
                            </select>
                            <input type="text" name="year" class="form-control" value="{{ carbon()->year }}"/>
                            <span class="input-group-append">
                                <button type="button" class="btn btn-info btn-flat btn-sm">{{ trans('calendar::paps.display_btn') }}</button>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="typeDistChart"></canvas>
                    <p class="text-center text-muted d-none" id="typeDistEmpty">{{ trans('calendar::paps.no_data') }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('calendar::paps.consumed_distribution_header') }}</h3>
                    <div class="card-tools">
                        <div class="input-group input-group-sm" style="width: 220px;" id="consumedSettings">
                            <select name="month" class="form-control">
                                <option value="">-- {{ trans('calendar::paps.all_months') }} --</option>
                                @for($i = 1; $i <= 12; $i++)
                                    <option value="{{ $i }}" @if($i == carbon()->month) selected @endif>{{ $i }}</option>
                                @endfor
                            </select>
                            <input type="text" name="year" class="form-control" value="{{ carbon()->year }}"/>
                            <span class="input-group-append">
                                <button type="button" class="btn btn-info btn-flat btn-sm">{{ trans('calendar::paps.display_btn') }}</button>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="text-muted small">{{ trans('calendar::paps.consumed_pap') }}</div>
                        <div class="display-4" id="consumedTotal">0.00</div>
                    </div>
                    <table class="table table-sm mb-0" id="consumedTable">
                        <tbody></tbody>
                    </table>
                    <p class="text-center text-muted d-none" id="consumedEmpty">{{ trans('calendar::paps.no_data') }}</p>
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
            <div class="card-tools">
                <div class="input-group input-group-sm" id="rankingSettings">
                    <select name="month" class="form-control">
                        <option value="">-- {{ trans('calendar::paps.all_months') }} --</option>
                        @for($i = 1; $i <= 12; $i++)
                            <option value="{{ $i }}" @if($i == carbon()->month) selected @endif>{{ $i }}</option>
                        @endfor
                    </select>
                    <input type="text" name="year" class="form-control" value="{{ carbon()->year }}"/>
                    <span class="input-group-append">
                        <button type="button" class="btn btn-info btn-flat btn-sm">{{ trans('calendar::paps.display_btn') }}</button>
                        <button type="button" class="btn btn-success btn-flat btn-sm" id="exportRankingBtn">
                            <i class="fas fa-file-excel"></i> {{ trans('calendar::paps.export_btn') }}
                        </button>
                    </span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <table class="table table-hover mb-0" id="rankingTable">
                <thead>
                <tr>
                    <th style="width: 45px;" class="text-center">#</th>
                    <th>{{ trans('calendar::paps.character_header') }}</th>
                    <th style="width: 200px;">{{ trans('calendar::paps.attendance_pap') }}</th>
                    <th style="width: 110px;" class="text-right">{{ trans('calendar::paps.consumed_pap') }}</th>
                    <th style="width: 110px;" class="text-right">{{ trans('calendar::paps.available_pap') }}</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p class="text-center text-muted d-none" id="rankingEmpty">{{ trans('calendar::paps.no_data') }}</p>
        </div>
    </div>
@stop

@push('javascript')
    <script type="text/javascript">
        $(function () {
            let trendChart, monthDistChart, yearDistChart;
            let defaultColors = ['#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997'];
            let monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            let themeColor = rgb2hex($('.nav-pills .nav-link.active').css('backgroundColor'));
            let currentRankingData = [];

            if (themeColor.substr(4) === rgb2hex($('.card').css('backgroundColor')).substr(4))
                themeColor = '#000000';

            // --- Monthly trend chart (AJAX) ---
            let trendUrl = '{{ route('corporation.ajax.paps.monthly-trend', request()->route('corporation')) }}';

            function loadTrend() {
                let year = $('#trendSettings').find('input[name="year"]').val();
                $.ajax({
                    url: trendUrl,
                    data: {year: year},
                    success: function (data) {
                        if (trendChart) trendChart.destroy();
                        $('#trendEmpty').addClass('d-none');
                        $('#monthlyTrendChart').show();

                        let trendValues = new Array(12).fill(0);
                        data.forEach(function (item) {
                            trendValues[item.month - 1] = parseFloat(item.attendance);
                        });

                        let hasData = trendValues.some(function (v) { return v !== 0; });
                        if (!hasData) {
                            $('#monthlyTrendChart').hide();
                            $('#trendEmpty').removeClass('d-none');
                            return;
                        }

                        trendChart = new Chart(document.getElementById('monthlyTrendChart').getContext('2d'), {
                            type: 'line',
                            data: {
                                labels: monthNames,
                                datasets: [{
                                    label: '{{ trans('calendar::paps.attendance_pap') }}',
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
                                    yAxes: [{ticks: {stepSize: 1}}]
                                }
                            }
                        });
                    }
                });
            }

            $('#trendSettings').find('button').on('click', loadTrend);
            loadTrend();

            // --- Type distribution ---
            function loadTypeDistribution(canvasId, emptyId, url, params, chartRef) {
                $.ajax({
                    url: url,
                    data: params,
                    success: function (data) {
                        if (chartRef.chart) chartRef.chart.destroy();
                        $('#' + emptyId).addClass('d-none');
                        $('#' + canvasId).show();

                        if (data.length < 1) {
                            $('#' + canvasId).hide();
                            $('#' + emptyId).removeClass('d-none');
                            return;
                        }

                        chartRef.chart = new Chart(document.getElementById(canvasId).getContext('2d'), {
                            type: 'doughnut',
                            data: {
                                labels: data.map(function (t) { return t.analytics || 'Unknown'; }),
                                datasets: [{
                                    data: data.map(function (t) { return parseFloat(t.qty); }),
                                    backgroundColor: data.map(function (t, i) { return t.bg_color || defaultColors[i % defaultColors.length]; })
                                }]
                            },
                            options: { legend: {position: 'bottom'} }
                        });
                    }
                });
            }

            let typeDistRef = {chart: null};
            let typeDistUrl = '{{ route('corporation.ajax.paps.type-distribution', request()->route('corporation')) }}';

            // 类型分布：选某月则按月，选「全部月份」则按整年
            function loadTypeDist() {
                let month = $('#typeDistSettings').find('select[name="month"]').val();
                let params = {year: $('#typeDistSettings').find('input[name="year"]').val()};
                if (month) params.month = month;
                loadTypeDistribution('typeDistChart', 'typeDistEmpty', typeDistUrl, params, typeDistRef);
            }
            $('#typeDistSettings').find('button').on('click', loadTypeDist);
            loadTypeDist();

            // --- 军团消费 PAP（同样可选某月或全部月份=年度） ---
            let consumedUrl = '{{ route('corporation.ajax.paps.consumed', request()->route('corporation')) }}';

            function loadConsumed() {
                let month = $('#consumedSettings').find('select[name="month"]').val();
                let params = {year: $('#consumedSettings').find('input[name="year"]').val()};
                if (month) params.month = month;
                $.ajax({
                    url: consumedUrl,
                    data: params,
                    success: function (data) {
                        $('#consumedTotal').text(parseFloat(data.total).toFixed(2));
                        let tbody = $('#consumedTable tbody');
                        tbody.empty();
                        if (!data.items || data.items.length < 1) {
                            $('#consumedEmpty').removeClass('d-none');
                            return;
                        }
                        $('#consumedEmpty').addClass('d-none');
                        $.each(data.items, function (i, item) {
                            let tr = $('<tr>');
                            tr.append($('<td>').text(item.title || ''));
                            tr.append($('<td class="text-right">').text(parseFloat(item.consumed).toFixed(2)));
                            tbody.append(tr);
                        });
                    }
                });
            }
            $('#consumedSettings').find('button').on('click', loadConsumed);
            loadConsumed();

            // --- Rankings ---
            let rankingUrl = '{{ route('corporation.ajax.paps.ranking', request()->route('corporation')) }}';

            function loadRanking() {
                let params = {
                    year: $('#rankingSettings').find('input[name="year"]').val()
                };
                let month = $('#rankingSettings').find('select[name="month"]').val();
                if (month) params.month = month;

                $.ajax({
                    url: rankingUrl,
                    data: params,
                    success: function (data) {
                        currentRankingData = data;
                        let tbody = $('#rankingTable tbody');
                        tbody.empty();
                        $('#rankingEmpty').addClass('d-none');

                        if (data.length < 1) {
                            $('#rankingEmpty').removeClass('d-none');
                            return;
                        }

                        // 进度条按出勤 PAP（默认排序口径）；最大值 <= 0 时不做除法，避免除零
                        let maxAttendance = Math.max.apply(null, data.map(function (d) { return parseFloat(d.attendance_pap); }));
                        let trophyColors = {1: '#FFD700', 2: '#C0C0C0', 3: '#CD7F32'};

                        $.each(data, function (index, item) {
                            let rank = index + 1;
                            let rankCell = rank <= 3
                                ? '<i class="fas fa-trophy" style="color: ' + trophyColors[rank] + ';"></i>'
                                : rank;
                            let attendance = parseFloat(item.attendance_pap);
                            let pct = maxAttendance > 0 ? Math.max(0, Math.round(attendance / maxAttendance * 100)) : 0;

                            tbody.append(
                                '<tr>' +
                                '<td class="text-center align-middle">' + rankCell + '</td>' +
                                '<td class="align-middle">' + (item.name || 'Unknown') + '</td>' +
                                '<td class="align-middle">' +
                                    '<div class="d-flex align-items-center">' +
                                        '<div class="progress flex-grow-1 mr-2" style="height:16px;">' +
                                            '<div class="progress-bar bg-info" style="width:' + pct + '%"></div>' +
                                        '</div>' +
                                        '<strong>' + attendance.toFixed(2) + '</strong>' +
                                    '</div>' +
                                '</td>' +
                                '<td class="align-middle text-right">' + parseFloat(item.consumed_pap).toFixed(2) + '</td>' +
                                '<td class="align-middle text-right">' + parseFloat(item.available_pap).toFixed(2) + '</td>' +
                                '</tr>'
                            );
                        });
                    }
                });
            }

            $('#rankingSettings').find('.btn-info').on('click', loadRanking);
            loadRanking();

            // --- Export Excel ---
            $('#exportRankingBtn').on('click', function () {
                if (currentRankingData.length < 1) return;

                let month = $('#rankingSettings').find('select[name="month"]').val();
                let year = $('#rankingSettings').find('input[name="year"]').val();
                let filename = 'pap_ranking_' + year + (month ? '_' + month : '') + '.csv';

                let csv = '{{ trans('calendar::paps.rank_label') }},{{ trans('calendar::paps.character_header') }},{{ trans('calendar::paps.attendance_pap') }},{{ trans('calendar::paps.consumed_pap') }},{{ trans('calendar::paps.available_pap') }}\n';
                $.each(currentRankingData, function (index, item) {
                    csv += (index + 1) + ',"' + (item.name || 'Unknown').replace(/"/g, '""') + '",' +
                        parseFloat(item.attendance_pap).toFixed(2) + ',' +
                        parseFloat(item.consumed_pap).toFixed(2) + ',' +
                        parseFloat(item.available_pap).toFixed(2) + '\n';
                });

                let blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
                let link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                link.click();
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
