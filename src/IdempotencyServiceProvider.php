<?php

namespace Webrek\Idempotency;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\Http\Middleware\EnsureIdempotency;
use Webrek\Idempotency\Repositories\CacheRepository;

class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/idempotency.php', 'idempotency');

        $this->app->singleton(IdempotencyRepository::class, function ($app): CacheRepository {
            /** @var Config $config */
            $config = $app['config'];

            return new CacheRepository(
                $app->make(CacheFactory::class),
                $config->get('idempotency.store'),
                (string) $config->get('idempotency.prefix', 'idempotency:'),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/idempotency.php' => $this->app->configPath('idempotency.php'),
            ], 'idempotency-config');
        }

        $this->app->make(Router::class)->aliasMiddleware('idempotency', EnsureIdempotency::class);
    }
}
