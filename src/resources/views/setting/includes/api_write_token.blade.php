<div class="card card-danger">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-lock"></i> {{ trans('calendar::seat.api_write_settings') }}</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">{{ trans('calendar::seat.api_write_description') }}</p>

        @if($apiWriteToken)
            <div class="form-group">
                <label>{{ trans('calendar::seat.api_write_token_label') }}</label>
                <div class="input-group">
                    <input type="text" class="form-control" value="{{ $apiWriteToken }}" readonly id="api-write-token-display"/>
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('api-write-token-display').value)" title="Copy">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>{{ trans('calendar::seat.api_write_usage') }}</label>
                <code class="d-block p-2 bg-light rounded" style="word-break: break-all;">
                    POST {{ url('/api/calendar/paps/debit') }}<br>
                    POST {{ url('/api/calendar/paps/refund') }}<br>
                    Authorization: Bearer {{ $apiWriteToken }}
                </code>
            </div>
        @else
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle"></i> {{ trans('calendar::seat.api_write_token_not_set') }}
            </div>
        @endif
    </div>
    <div class="card-footer">
        <form method="POST" action="{{ route('setting.api_write_token.regenerate') }}" class="d-inline">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-warning">
                <i class="fas fa-sync-alt"></i> {{ $apiWriteToken ? trans('calendar::seat.api_token_regenerate') : trans('calendar::seat.api_token_generate') }}
            </button>
        </form>
        @if($apiWriteToken)
            <form method="POST" action="{{ route('setting.api_write_token.delete') }}" class="d-inline float-right">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-danger" onclick="return confirm('{{ trans('calendar::seat.api_token_delete_confirm') }}')">
                    <i class="fas fa-trash"></i> {{ trans('calendar::seat.api_token_delete') }}
                </button>
            </form>
        @endif
    </div>
</div>
