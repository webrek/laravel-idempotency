<?php

namespace Webrek\Idempotency\Tests;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
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
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('session.driver', 'array');
    }

    /**
     * The cache key the middleware derives for an unscoped idempotency key,
     * mirroring `EnsureIdempotency::cacheKey()`.
     */
    protected function cacheKeyFor(string $key): string
    {
        return hash('sha256', 'k:' . hash('sha256', $key));
    }

    /**
     * Testbench defines no middleware groups of its own, so tests that need
     * session-backed behaviour (flash replay, redirect-back) register a
     * "web" group mirroring a real Laravel application's default stack.
     */
    protected function defineWebMiddlewareGroup(Router $router): void
    {
        $router->middlewareGroup('web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
        ]);
    }
}
