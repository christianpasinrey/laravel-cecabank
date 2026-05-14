<?php

namespace Cpr\Cecabank\Tests;

use Cpr\Cecabank\CecabankServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CecabankServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Default cecabank.* config (the package provides defaults; we only
        // override what tests need to assert against fixed values).
        $app['config']->set('cecabank.fallback_routes', [
            'success' => 'test.success',
            'failure' => 'test.failure',
        ]);
    }

    protected function defineRoutes($router): void
    {
        // Stub routes the package's success/failure redirects can resolve to.
        $router->get('/__test/success', fn () => 'ok')->name('test.success');
        $router->get('/__test/failure', fn () => 'nok')->name('test.failure');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
