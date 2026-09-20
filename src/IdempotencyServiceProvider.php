<?php

namespace Webrek\Idempotency;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
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
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'idempotency');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/idempotency.php' => $this->app->configPath('idempotency.php'),
            ], 'idempotency-config');

            $this->publishes([
                __DIR__ . '/../lang' => $this->app->langPath('vendor/idempotency'),
            ], 'idempotency-lang');
        }

        $this->app->make(Router::class)->aliasMiddleware('idempotency', EnsureIdempotency::class);

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            $blade->directive('idempotencyKey', fn (): string => '<?php echo \\Illuminate\\Container\\Container::getInstance()->make(\\Webrek\\Idempotency\\Blade\\KeyField::class)->render(); ?>');
        });
    }
}
