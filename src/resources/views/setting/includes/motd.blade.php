<div class="card card-info">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-comment-alt"></i> {{ trans('calendar::seat.motd_settings') }}</h3>
    </div>
    <div class="card-body">
        <form class="form-horizontal" method="POST" action="{{ route('setting.motd.update') }}" id="form-motd-settings">
            {{ csrf_field() }}

            <h5 class="mb-3">{{ trans('calendar::seat.motd_colors') }}</h5>

            @foreach([
                'motd_color_header'    => 'motd_label_header',
                'motd_color_fleet'     => 'motd_label_fleet',
                'motd_color_members'   => 'motd_label_members',
                'motd_color_pap_value' => 'motd_label_pap_value',
                'motd_color_pap_type'  => 'motd_label_pap_type',
                'motd_color_time'      => 'motd_label_time',
                'motd_color_error'     => 'motd_label_error',
            ] as $field => $label)
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">{{ trans('calendar::seat.' . $label) }}</label>
                    <div class="col-sm-9">
                        <div class="input-group colorpicker-component motd-color-picker" id="picker_{{ $field }}">
                            <input type="text" name="{{ $field }}" value="#{{ $motd[$field] }}" class="form-control" maxlength="7"/>
                            <span class="input-group-addon"><i></i></span>
                        </div>
                    </div>
                </div>
            @endforeach

            <hr>
            <h5 class="mb-3">{{ trans('calendar::seat.motd_footer_section') }}</h5>

            <div class="form-group row">
                <label class="col-sm-3 col-form-label">{{ trans('calendar::seat.motd_label_footer_text') }}</label>
                <div class="col-sm-9">
                    <input type="text" name="motd_footer_text" value="{{ $motd['motd_footer_text'] }}" class="form-control" maxlength="100" id="motd_footer_text"/>
                </div>
            </div>

            <div class="form-group row">
                <label class="col-sm-3 col-form-label">{{ trans('calendar::seat.motd_label_footer_color') }}</label>
                <div class="col-sm-9">
                    <div class="input-group colorpicker-component motd-color-picker" id="picker_motd_footer_color">
                        <input type="text" name="motd_footer_color" value="#{{ $motd['motd_footer_color'] }}" class="form-control" maxlength="7"/>
                        <span class="input-group-addon"><i></i></span>
                    </div>
                </div>
            </div>

            <hr>
            <h5 class="mb-3">{{ trans('calendar::seat.motd_preview') }}</h5>
            <div class="p-3 rounded" style="background-color: #1a1a2e; font-family: monospace; line-height: 1.8;" id="motd-preview"></div>
        </form>
    </div>
    <div class="card-footer">
        <button type="submit" class="btn btn-info float-right" form="form-motd-settings">{{ trans('calendar::seat.save') }}</button>
    </div>
</div>

@push('javascript')
<script type="text/javascript">
$(function () {
    $('.motd-color-picker').colorpicker();

    function updatePreview() {
        var g = function(name) {
            var v = $('input[name="' + name + '"]').val() || '#ffffff';
            return v.replace('#', '');
        };

        var footer = $('#motd_footer_text').val();

        var html = '<span style="color:#' + g('motd_color_header') + ';font-weight:bold;">✦ PAP Issued ✦</span><br>'
            + '<span style="color:#' + g('motd_color_fleet') + ';">{{ trans("calendar::paps.motd_fleet") }}</span> '
            + '<span style="color:#' + g('motd_color_fleet') + ';">Example Fleet Op</span><br>'
            + '<span style="color:#' + g('motd_color_fleet') + ';">{{ trans("calendar::paps.motd_members") }}</span> '
            + '<span style="color:#' + g('motd_color_members') + ';">25</span><br>'
            + '<span style="color:#' + g('motd_color_fleet') + ';">{{ trans("calendar::paps.motd_pap_value") }}</span> '
            + '<span style="color:#' + g('motd_color_pap_value') + ';">1</span><br>'
            + '<span style="color:#' + g('motd_color_pap_type') + ';">{{ trans("calendar::paps.motd_type") }}</span> '
            + '<span style="color:#' + g('motd_color_pap_type') + ';">[Strategic]</span><br>'
            + '<span style="color:#' + g('motd_color_time') + ';">{{ trans("calendar::paps.motd_time") }}</span> '
            + '<span style="color:#' + g('motd_color_time') + ';">2026-03-28 20:00 EVE</span>';

        if (footer) {
            html += '<br><span style="color:#' + g('motd_footer_color') + ';">' + $('<span>').text(footer).html() + '</span>';
        }

        $('#motd-preview').html(html);
    }

    $('.motd-color-picker').on('colorpickerChange', updatePreview);
    $('#motd_footer_text').on('input', updatePreview);
    updatePreview();
});
</script>
@endpush
