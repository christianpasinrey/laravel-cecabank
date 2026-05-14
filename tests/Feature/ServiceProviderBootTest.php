<?php

namespace Cpr\Cecabank\Tests\Feature;

use Cpr\Cecabank\Tests\TestCase;
use RuntimeException;

class ServiceProviderBootTest extends TestCase
{
    public function test_default_urls_are_accepted(): void
    {
        // If the provider booted successfully in setUp(), defaults are safe.
        $this->assertSame('https://tpv.ceca.es/tpvweb/tpv/compra.action', config('cecabank.urls.test'));
        $this->assertSame('https://pgw.ceca.es/tpvweb/tpv/compra.action', config('cecabank.urls.production'));
    }
}

/**
 * Separate TestCase variant that overrides config BEFORE the package boots,
 * to assert the URL allow-list refuses unsafe values.
 */
class ServiceProviderBootRejectsHttpTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cecabank.urls.production', 'http://attacker.example.com/steal');
    }

    public function test_boot_throws_when_production_url_is_not_https(): void
    {
        // Forcing config re-read because Orchestra defers boot; we trigger by
        // reading any cecabank config which routes back through the provider.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must use https|not in the allow-list/');

        // Re-boot the provider explicitly so the assertion runs against the
        // overridden config.
        $provider = new \Cpr\Cecabank\CecabankServiceProvider($this->app);
        $provider->boot();
    }
}

class ServiceProviderBootRejectsForeignHostTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cecabank.urls.production', 'https://attacker.example.com/steal');
    }

    public function test_boot_throws_when_host_not_in_allow_list(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not in the allow-list/');

        $provider = new \Cpr\Cecabank\CecabankServiceProvider($this->app);
        $provider->boot();
    }
}
