<div class="card card-warning">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-calendar-day"></i> {{ trans('calendar::seat.pap_start_settings') }}</h3>
    </div>
    <form method="POST" action="{{ route('setting.pap_start_date.update') }}">
        {{ csrf_field() }}
        <div class="card-body">
            <p class="text-muted">{{ trans('calendar::seat.pap_start_description') }}</p>

            <div class="form-group">
                <label>{{ trans('calendar::seat.pap_start_label') }}</label>
                <input type="month" class="form-control" name="pap_start_date"
                       value="{{ $papStartMonth }}" required/>
                <small class="form-text text-muted">{{ trans('calendar::seat.pap_start_hint') }}</small>
            </div>

            <div class="alert alert-warning mb-0">
                <i class="fas fa-exclamation-triangle"></i> {{ trans('calendar::seat.pap_start_warning') }}
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-warning">
                <i class="fas fa-save"></i> {{ trans('calendar::seat.save') }}
            </button>
        </div>
    </form>
</div>
