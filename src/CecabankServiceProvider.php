<?php

namespace Cpr\Cecabank;

use Cpr\Cecabank\Models\PaymentGateway;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class CecabankServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cecabank.php', 'cecabank');

        $this->app->singleton(CecabankService::class);
    }

    public function boot(): void
    {
        $this->assertGatewayUrlsAreSafe();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'cecabank');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cecabank');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->registerPublishing();

        // Resolve {paymentGateway} route params to the package model so
        // host-side admin routes can use route-model binding transparently.
        $this->app['router']->bind('paymentGateway', fn ($value) => PaymentGateway::query()->findOrFail($value));
    }

    /**
     * Refuse to boot if `cecabank.urls.{test,production}` would direct the
     * client browser POST anywhere other than an https Cecabank-owned host.
     * Defends against a leaked / mis-set env redirecting card-bearing traffic
     * to an attacker origin.
     */
    protected function assertGatewayUrlsAreSafe(): void
    {
        $allowedHostSuffixes = (array) config('cecabank.allowed_url_host_suffixes', ['.ceca.es']);

        foreach (['test', 'production'] as $env) {
            $url = (string) config("cecabank.urls.{$env}", '');
            if ($url === '') {
                continue; // hosts may opt out of one environment
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = (string) parse_url($url, PHP_URL_HOST);

            if ($scheme !== 'https') {
                throw new RuntimeException(
                    "Refusing to boot cpr/laravel-cecabank: config('cecabank.urls.{$env}') must use https (got: ".($scheme ?: 'no scheme').')'
                );
            }

            $hostOk = false;
            foreach ($allowedHostSuffixes as $suffix) {
                if (str_ends_with($host, (string) $suffix)) {
                    $hostOk = true;
                    break;
                }
            }

            if (! $hostOk) {
                $expected = implode(', ', $allowedHostSuffixes);
                throw new RuntimeException(
                    "Refusing to boot cpr/laravel-cecabank: config('cecabank.urls.{$env}') host '{$host}' is not in the allow-list ({$expected})."
                );
            }
        }
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
