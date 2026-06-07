<?php

namespace Webrek\Idempotency\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Webrek\Idempotency\IdempotencyServiceProvider;
use Webrek\Idempotency\Tests\Support\Counter;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Counter::reset();
    }

    protected function getPackageProviders($app): array
    {
        return [
            IdempotencyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('idempotency.store', 'array');
    }
}
