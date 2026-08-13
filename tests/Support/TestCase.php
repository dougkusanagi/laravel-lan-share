<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Tests\Support;

use DougKusanagi\LaravelLanShare\LanShareServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LanShareServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('lan-share.pairing.token_ttl', 90);
        $app['config']->set('lan-share.pairing.status_ttl', 300);
    }
}
