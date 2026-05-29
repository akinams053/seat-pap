@extends('web::layouts.grids.12')

@section('title', trans('calendar::seat.plugin_name') . ' | ' . trans('calendar::lottery.create_title'))
@section('page_header', trans('calendar::lottery.create_title'))

@section('full')

    <form method="POST" action="{{ route('lottery.store') }}">
        {{ csrf_field() }}

        <div class="card">
            <div class="card-body">

                <div class="form-group">
                    <label>{{ trans('calendar::lottery.form_title') }}</label>
                    <input type="text" name="title" class="form-control"
                           value="{{ old('title') }}"
                           placeholder="{{ trans('calendar::lottery.form_title_ph') }}" required>
                </div>

                <div class="row">
                    <div class="col-md-4 form-group">
                        <label>{{ trans('calendar::lottery.form_node_count') }}</label>
                        <input type="number" name="node_count" class="form-control"
                               min="1" max="10000" step="1"
                               value="{{ old('node_count') }}" required>
                    </div>
                    <div class="col-md-4 form-group">
                        <label>{{ trans('calendar::lottery.form_node_price') }}</label>
                        <input type="number" name="node_price" class="form-control"
                               min="0.01" max="999999.99" step="0.01"
                               value="{{ old('node_price') }}" required>
                    </div>
                    <div class="col-md-4 form-group">
                        <label>{{ trans('calendar::lottery.form_max_per_user') }}</label>
                        <input type="number" name="max_nodes_per_user" class="form-control"
                               min="1" step="1" value="{{ old('max_nodes_per_user') }}">
                        <small class="form-text text-muted">
                            {{ trans('calendar::lottery.form_max_per_user_hint') }}
                        </small>
                    </div>
                </div>

                <div class="form-group">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="allow_repeat_winners"
                               name="allow_repeat_winners" value="1"
                               {{ old('allow_repeat_winners') ? 'checked' : '' }}>
                        <label class="custom-control-label" for="allow_repeat_winners">
                            {{ trans('calendar::lottery.form_allow_repeat') }}
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <strong>{{ trans('calendar::lottery.form_prizes') }}</strong>
                <button type="button" class="btn btn-success btn-sm float-right" id="add-prize-btn">
                    <i class="fas fa-plus"></i> {{ trans('calendar::lottery.add_prize') }}
                </button>
            </div>
            <div class="card-body">
                <div id="prize-rows"></div>
            </div>
        </div>

        <div class="form-group">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> {{ trans('calendar::lottery.submit_btn') }}
            </button>
            <a href="{{ route('lottery.index') }}" class="btn btn-secondary">
                {{ trans('calendar::lottery.back_to_list') }}
            </a>
        </div>
    </form>

@stop

@push('javascript')
    <script>
        var prizeIndex = 0;

        function prizeRowHtml(index, name, desc) {
            return '' +
                '<div class="prize-row border rounded p-2 mb-2" data-index="' + index + '">' +
                '  <div class="row">' +
                '    <div class="col-md-5 form-group mb-1">' +
                '      <label class="mb-1">{{ trans('calendar::lottery.form_prize_name') }}</label>' +
                '      <input type="text" class="form-control" name="prizes[' + index + '][name]" ' +
                '             placeholder="{{ trans('calendar::lottery.form_prize_name_ph') }}" ' +
                '             value="' + (name || '') + '" required>' +
                '    </div>' +
                '    <div class="col-md-6 form-group mb-1">' +
                '      <label class="mb-1">{{ trans('calendar::lottery.form_prize_desc') }}</label>' +
                '      <input type="text" class="form-control" name="prizes[' + index + '][description]" ' +
                '             placeholder="{{ trans('calendar::lottery.form_prize_desc_ph') }}" ' +
                '             value="' + (desc || '') + '">' +
                '    </div>' +
                '    <div class="col-md-1 form-group mb-1 d-flex align-items-end">' +
                '      <button type="button" class="btn btn-danger btn-sm btn-remove-prize" ' +
                '              title="{{ trans('calendar::lottery.remove_prize') }}">' +
                '        <i class="fas fa-trash"></i></button>' +
                '    </div>' +
                '  </div>' +
                '</div>';
        }

        function addPrizeRow(name, desc) {
            $('#prize-rows').append(prizeRowHtml(prizeIndex, name, desc));
            prizeIndex++;
        }

        $('#add-prize-btn').on('click', function () { addPrizeRow(); });

        $(document).on('click', '.btn-remove-prize', function () {
            $(this).closest('.prize-row').remove();
        });

        // 至少保留一行
        @if(old('prizes'))
            @foreach(old('prizes') as $p)
                addPrizeRow(@json($p['name'] ?? ''), @json($p['description'] ?? ''));
            @endforeach
        @else
            addPrizeRow();
        @endif
    </script>
@endpush
