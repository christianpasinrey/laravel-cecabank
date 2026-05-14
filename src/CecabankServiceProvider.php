<?php

namespace Cpr\Cecabank;

use Cpr\Cecabank\Models\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class CecabankServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cecabank.php', 'cecabank');

        $this->app->singleton(CecabankService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'cecabank');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cecabank');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->registerPublishing();

        // Resolve {paymentGateway} route params to the package model so
        // host-side admin routes can use route-model binding transparently.
        $this->app['router']->bind('paymentGateway', fn ($value) => PaymentGateway::query()->findOrFail($value));
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/cecabank.php' => config_path('cecabank.php'),
        ], 'cecabank-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cecabank-migrations');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/cecabank'),
        ], 'cecabank-lang');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/cecabank'),
        ], 'cecabank-views');
    }
}
