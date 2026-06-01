<div class="card card-info">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-dice"></i> {{ trans('calendar::seat.lottery_settings') }}</h3>
    </div>
    <form method="POST" action="{{ route('setting.lottery_url.update') }}">
        {{ csrf_field() }}
        <div class="card-body">
            <p class="text-muted">{{ trans('calendar::seat.lottery_description') }}</p>

            <div class="form-group">
                <label>{{ trans('calendar::seat.lottery_url_label') }}</label>
                <input type="url" class="form-control" name="lottery_url"
                       value="{{ $lotteryUrl }}"
                       placeholder="https://lottery.example.com/auth"/>
            </div>

            @if($lotteryUrl)
                <div class="form-group">
                    <label>{{ trans('calendar::seat.shop_jwt_info') }}</label>
                    <div class="small text-muted">
                        <p class="mb-1">JWT payload:</p>
                        <code class="d-block p-2 bg-light rounded">
                            { "sub": user_id, "main_character_id": ..., "name": "...", "balance": ..., "iat": ..., "exp": ... }
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
