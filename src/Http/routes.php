<?php

use Illuminate\Support\Facades\Route;

// PAP API — token 认证，供外部服务调用
// 用插件自有 `throttle:calendar-api`（300/min）替代宿主默认 `api` 组的 60/min
Route::group([
    'namespace' => 'Seat\\Kassie\\Calendar\\Http\\Controllers',
    'middleware' => ['throttle:calendar-api', \Illuminate\Routing\Middleware\SubstituteBindings::class, 'calendar.api.token'],
    'prefix' => 'api/calendar',
], function (): void {

    Route::get('/paps', [
        'as' => 'api.calendar.paps.batch',
        'uses' => 'ApiController@getBatchPaps',
    ]);

    Route::get('/paps/{character_id}', [
        'as' => 'api.calendar.paps.character',
        'uses' => 'ApiController@getCharacterPaps',
    ])->where('character_id', '[0-9]+');

});

// PAP 写接口 — 独立写 token；抽奖走 settle，debit/refund 仅保留给未来商店
Route::group([
    'namespace' => 'Seat\\Kassie\\Calendar\\Http\\Controllers',
    'middleware' => ['throttle:calendar-api', \Illuminate\Routing\Middleware\SubstituteBindings::class, 'calendar.api.write_token'],
    'prefix' => 'api/calendar',
], function (): void {

    Route::post('/paps/lottery/settle', [
        'as' => 'api.calendar.paps.lottery.settle',
        'uses' => 'ApiController@lotterySettle',
    ]);

    Route::post('/paps/debit', [
        'as' => 'api.calendar.paps.debit',
        'uses' => 'ApiController@debit',
    ]);

    Route::post('/paps/refund', [
        'as' => 'api.calendar.paps.refund',
        'uses' => 'ApiController@refund',
    ]);

});

Route::group([
    'namespace' => 'Seat\Kassie\Calendar\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'character',
], function (): void {

    Route::get('/{character}/paps', [
        'as' => 'character.view.paps',
        'uses' => 'CharacterController@paps',
        'middleware' => 'can:character.kassie_calendar_paps,character',
    ]);

});

Route::group([
    'namespace' => 'Seat\Kassie\Calendar\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'corporation',
], function (): void {

    Route::get('/{corporation}/paps', [
        'as' => 'corporation.view.paps',
        'uses' => 'CorporationController@getPaps',
        'middleware' => 'can:corporation.kassie_calendar_paps,corporation',
    ]);

    Route::get('/{corporation}/paps/json/monthly-trend', [
        'as' => 'corporation.ajax.paps.monthly-trend',
        'uses' => 'CorporationController@getMonthlyTrendJson',
        'middleware' => 'can:corporation.kassie_calendar_paps,corporation',
    ]);

    Route::get('/{corporation}/paps/json/type-distribution', [
        'as' => 'corporation.ajax.paps.type-distribution',
        'uses' => 'CorporationController@getTypeDistributionJson',
        'middleware' => 'can:corporation.kassie_calendar_paps,corporation',
    ]);

    Route::get('/{corporation}/paps/json/ranking', [
        'as' => 'corporation.ajax.paps.ranking',
        'uses' => 'CorporationController@getRankingJson',
        'middleware' => 'can:corporation.kassie_calendar_paps,corporation',
    ]);

    Route::get('/{corporation}/paps/json/consumed', [
        'as' => 'corporation.ajax.paps.consumed',
        'uses' => 'CorporationController@getConsumedJson',
        'middleware' => 'can:corporation.kassie_calendar_paps,corporation',
    ]);

});

Route::group([
    'namespace' => 'Seat\Kassie\Calendar\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale', 'can:calendar.view'],
    'prefix' => 'calendar'
], function (): void {

    Route::group([
        'prefix' => 'ajax'
    ], function (): void {

        Route::get('/operation/{id}', [
            'as' => 'operation.detail',
            'uses' => 'AjaxController@getDetail'
        ])->where('id', '[0-9]+');

        Route::get('/operation/ongoing', [
            'as' => 'operation.ongoing',
            'uses' => 'AjaxController@getOngoing',
        ]);

        Route::get('/operation/incoming', [
            'as' => 'operation.incoming',
            'uses' => 'AjaxController@getIncoming',
        ]);

        Route::get('/operation/faded', [
            'as' => 'operation.faded',
            'uses' => 'AjaxController@getFaded',
        ]);
    });

    Route::group([
        'prefix' => 'operation'
    ], function (): void {

        Route::get('/', [
            'as' => 'operation.index',
            'uses' => 'OperationController@index'
        ]);

        Route::post('/', [
            'as' => 'operation.store',
            'uses' => 'OperationController@store',
            'middleware' => 'can:calendar.create'
        ]);

        Route::post('update', [
            'as' => 'operation.update',
            'uses' => 'OperationController@update',
        ]);

        Route::post('subscribe', [
            'as' => 'operation.subscribe',
            'uses' => 'OperationController@subscribe'
        ]);

        Route::post('cancel', [
            'as' => 'operation.cancel',
            'uses' => 'OperationController@cancel',
        ]);

        Route::post('activate', [
            'as' => 'operation.activate',
            'uses' => 'OperationController@activate',
        ]);

        Route::post('close', [
            'as' => 'operation.close',
            'uses' => 'OperationController@close'
        ]);

        Route::post('delete', [
            'as' => 'operation.delete',
            'uses' => 'OperationController@delete',
        ]);

        Route::get('{id}', 'OperationController@index');

        Route::get('find/{id}', 'OperationController@find');

        Route::get('/{id}/paps/preview', [
            'as' => 'operation.paps.preview',
            'uses' => 'OperationController@papsPreview',
        ]);

        Route::post('/{id}/paps/confirm', [
            'as' => 'operation.paps.confirm',
            'uses' => 'OperationController@papsConfirm',
        ]);

        Route::get('/{id}/audit/members', [
            'as' => 'operation.audit.members',
            'uses' => 'AuditController@membersJson',
        ])->where('id', '[0-9]+');

        Route::post('/{id}/audit/adjust', [
            'as' => 'operation.audit.adjust',
            'uses' => 'AuditController@adjust',
        ])->where('id', '[0-9]+');

        Route::post('/{id}/audit/zero', [
            'as' => 'operation.audit.zero',
            'uses' => 'AuditController@zero',
        ])->where('id', '[0-9]+');

    });

    // 行动审查
    Route::group([
        'prefix' => 'audit',
    ], function (): void {

        Route::get('/', [
            'as' => 'audit.index',
            'uses' => 'AuditController@index',
        ]);

        Route::get('/operations', [
            'as' => 'audit.operations.json',
            'uses' => 'AuditController@operationsJson',
        ]);

    });

    // PAP 商店跳转
    Route::get('shop/redirect', [
        'as' => 'calendar.shop.redirect',
        'uses' => 'SettingController@shopRedirect',
    ]);

    // 抽奖外链跳转（玩法在外部服务，SeAT 只签发带余额快照的 JWT）
    Route::get('lottery/redirect', [
        'as' => 'calendar.lottery.redirect',
        'uses' => 'SettingController@lotteryRedirect',
    ]);

    Route::group([
        'prefix' => 'setting',
        'middleware' => 'can:calendar.setup'
    ], function (): void {

        Route::get('/', [
            'as' => 'setting.index',
            'uses' => 'SettingController@index'
        ]);

        Route::post('motd', [
            'as' => 'setting.motd.update',
            'uses' => 'SettingController@updateMotd',
        ]);

        Route::post('api-token/regenerate', [
            'as' => 'setting.api_token.regenerate',
            'uses' => 'SettingController@regenerateApiToken',
        ]);

        Route::post('api-token/delete', [
            'as' => 'setting.api_token.delete',
            'uses' => 'SettingController@deleteApiToken',
        ]);

        Route::post('api-write-token/regenerate', [
            'as' => 'setting.api_write_token.regenerate',
            'uses' => 'SettingController@regenerateApiWriteToken',
        ]);

        Route::post('api-write-token/delete', [
            'as' => 'setting.api_write_token.delete',
            'uses' => 'SettingController@deleteApiWriteToken',
        ]);

        Route::post('shop-url', [
            'as' => 'setting.shop_url.update',
            'uses' => 'SettingController@updateShopUrl',
        ]);

        Route::post('lottery-url', [
            'as' => 'setting.lottery_url.update',
            'uses' => 'SettingController@updateLotteryUrl',
        ]);

        Route::post('pap-start-date', [
            'as' => 'setting.pap_start_date.update',
            'uses' => 'SettingController@updatePapStartDate',
        ]);

        Route::group([
            'prefix' => 'tag'
        ], function (): void {

            Route::post('create', [
                'as' => 'setting.tag.create',
                'uses' => 'TagController@store'
            ]);

            Route::post('delete', [
                'as' => 'setting.tag.delete',
                'uses' => 'TagController@delete'
            ]);

            Route::get('show/{id}', [
                'as' => 'tags.show',
                'uses' => 'TagController@get',
                'middleware' => 'can:calendar.setup',
            ]);

            Route::post('update', [
                'as' => 'setting.tag.update',
                'uses' => 'TagController@store'
            ]);

        });

    });

    Route::group([
        'prefix' => 'lookup'
    ], function (): void {

        Route::get('characters', 'LookupController@lookupCharacters')->name('calendar.lookups.characters');
        Route::get('systems', 'LookupController@lookupSystems')->name('calendar.lookups.systems');
        Route::get('attendees', 'LookupController@lookupAttendees');
        Route::get('confirmed', 'LookupController@lookupConfirmed');

    });


});
