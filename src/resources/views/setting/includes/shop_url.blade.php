<div class="card card-info">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-shopping-cart"></i> {{ trans('calendar::seat.shop_settings') }}</h3>
    </div>
    <form method="POST" action="{{ route('setting.shop_url.update') }}">
        {{ csrf_field() }}
        <div class="card-body">
            <p class="text-muted">{{ trans('calendar::seat.shop_description') }}</p>

            <div class="form-group">
                <label>{{ trans('calendar::seat.shop_url_label') }}</label>
                <input type="url" class="form-control" name="shop_url"
                       value="{{ $shopUrl }}"
                       placeholder="https://shop.example.com/auth"/>
            </div>

            @if($shopUrl)
                <div class="form-group">
                    <label>{{ trans('calendar::seat.shop_jwt_info') }}</label>
                    <div class="small text-muted">
                        <p class="mb-1">JWT payload:</p>
                        <code class="d-block p-2 bg-light rounded">
                            { "sub": user_id, "main_character_id": ..., "name": "...", "iat": ..., "exp": ... }
                        </code>
                        <p class="mt-2 mb-0">{{ trans('calendar::seat.shop_jwt_sign_hint') }}</p>
                    </div>
                </div>
            @endif
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-info">
                <i class="fas fa-save"></i> {{ trans('calendar::seat.save') }}
            </button>
        </div>
    </form>
</div>
