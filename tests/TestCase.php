<?php

namespace Modules\TransactionVerification\Tests;

use Modules\TransactionVerification\Providers\TransactionVerificationServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;
use Spectator\SpectatorServiceProvider;

/** Boots the package in a bare Laravel app (sqlite in memory), the way any host app would. */
abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [TransactionVerificationServiceProvider::class, SpectatorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Encrypted casts and the blind-index fallback need a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('spectator.sources.local.base_path', dirname(__DIR__).'/openapi');
    }
}
