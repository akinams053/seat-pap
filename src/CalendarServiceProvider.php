<?php

namespace Seat\Kassie\Calendar;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Seat\Kassie\Calendar\Http\Middleware\ApiTokenMiddleware;
use Seat\Kassie\Calendar\Http\Middleware\ApiWriteTokenMiddleware;
use Seat\Services\AbstractSeatPlugin;

/**
 * Class CalendarServiceProvider.
 * @package Seat\Kassie\Calendar
 */
class CalendarServiceProvider extends AbstractSeatPlugin
{
    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('calendar.api.token', ApiTokenMiddleware::class);
        $this->app['router']->aliasMiddleware('calendar.api.write_token', ApiWriteTokenMiddleware::class);

        $this->registerApiRateLimiter();
        $this->addRoutes();
        $this->addViews();
        $this->addTranslations();
        $this->addMigrations();
        $this->addPublications();
    }

    private function addRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');
    }

    /**
     * 插件自有 API 限流器：替代宿主默认 `throttle:api`（60/min）。
     * 抽奖 / 商店服务端调用为可信的 token 鉴权请求，按调用方 IP 放宽到 300/min，
     * 仅作用于本插件 API 路由，不改动宿主全局限流。
     */
    private function registerApiRateLimiter(): void
    {
        RateLimiter::for('calendar-api', fn (Request $request): Limit =>
            Limit::perMinute(300)->by($request->ip()));
    }

    private function addViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'calendar');
    }

    private function addTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'calendar');
    }

    private function addMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }

    private function addPublications(): void
    {
        $this->publishes([
            __DIR__ . '/resources/assets/css' => public_path('web/css'),
            __DIR__ . '/resources/assets/vendors/css' => public_path('web/css'),
            __DIR__ . '/resources/assets/js' => public_path('web/js'),
            __DIR__ . '/resources/assets/vendors/js' => public_path('web/js'),
        ]);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/package.sidebar.php', 'package.sidebar');
        $this->mergeConfigFrom(__DIR__ . '/Config/calendar.character.menu.php', 'package.character.menu');
        $this->mergeConfigFrom(__DIR__ . '/Config/calendar.corporation.menu.php', 'package.corporation.menu');

        $this->registerPermissions(__DIR__ . '/Config/Permissions/calendar.php', 'calendar');
        $this->registerPermissions(__DIR__ . '/Config/Permissions/character.php', 'character');
        $this->registerPermissions(__DIR__ . '/Config/Permissions/corporation.php', 'corporation');
    }

    /**
     * Return the plugin public name as it should be displayed into settings.
     *
     * @return string
     * @example SeAT Web
     *
     */
    public function getName(): string
    {
        return 'Calendar';
    }

    /**
     * Return the plugin repository address.
     *
     * @example https://github.com/eveseat/web
     *
     * @return string
     */
    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/akinams053/seat-pap';
    }

    /**
     * Return the plugin technical name as published on package manager.
     *
     * @return string
     * @example web
     *
     */
    public function getPackagistPackageName(): string
    {
        return 'seat-pap';
    }

    /**
     * Return the plugin vendor tag as published on package manager.
     *
     * @return string
     * @example eveseat
     *
     */
    public function getPackagistVendorName(): string
    {
        return 'akinams053';
    }
}
