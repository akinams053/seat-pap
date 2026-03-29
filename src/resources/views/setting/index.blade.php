@extends('web::layouts.grids.12')

@section('title', trans('calendar::seat.plugin_name') . ' | ' . trans('calendar::seat.settings'))
@section('page_header', trans('calendar::seat.settings'))

@section('full')
    @include('calendar::setting.includes.tags')

    @include('calendar::setting.includes.modals.confirm_delete_tag')

    @include('calendar::setting.includes.modals.edit_tag')

    @include('calendar::setting.includes.motd')

    @include('calendar::setting.includes.api_token')

    @include('calendar::setting.includes.shop_url')
@stop

@push('head')
    <link rel="stylesheet" href="{{ asset('web/css/bootstrap-colorpicker.min.css') }}"/>
    <link rel="stylesheet" href="{{ asset('web/css/calendar.css') }}"/>
@endpush

@push('javascript')
    <script type="text/javascript" src="{{ asset('web/js/bootstrap-colorpicker.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('web/js/settings.js') }}"></script>
    <script type="text/javascript">
        const bgEditPicker = $('#edit_tag_bg_color').colorpicker();
        const fgEditPicker = $('#edit_tag_text_color').colorpicker();

        $('#modalEdit').on('show.bs.modal', function (e) {
            const link = '{{ route('tags.show', 0) }}';
            const modal = $(this);

            modal.find('.overlay').removeClass('d-none').addClass('d-flex');
            modal.find('input[type="text"]').each(function (index, input) {
                $(input).val('');
            });

            $.ajax({
                url: link.replace('/0', '/' + $(e.relatedTarget).attr('data-tag-id')),
                dataType: 'json',
                method: 'GET'
            }).done(function (data) {
                bgEditPicker.colorpicker('setValue', data.bg_color);
                fgEditPicker.colorpicker('setValue', data.text_color);

                modal.find('input[name="name"]').val(data.name);
                modal.find('input[name="order"]').val(data.order);
                modal.find('select[name="analytics"]').val(data.analytics);
                modal.find('input[name="quantifier"]').val(data.quantifier);
                modal.find('input[name="tag_id"]').val(data.id);
                modal.find('input[name="bg_color"]').val(data.bg_color);
                modal.find('input[name="text_color"]').val(data.text_color);

                modal.find('.overlay').addClass('d-none').removeClass('d-flex');
            });
        });
    </script>
@endpush
