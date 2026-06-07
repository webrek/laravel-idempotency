<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Routing\Router;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\TestCase;

class IdempotencyMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('idempotency')->group(function (Router $router): void {
            $router->post('/orders', fn () => response()->json(['id' => Counter::next()], 201));
            $router->get('/orders', fn () => response()->json(['id' => Counter::next()]));
            $router->post('/boom', fn () => response()->json(['n' => Counter::next()], 500));
        });
    }

    public function test_first_request_executes_and_is_marked_fresh(): void
    {
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'abc'])
            ->assertStatus(201)
            ->assertJson(['id' => 1])
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->assertSame(1, Counter::$count);
    }

    public function test_repeated_key_replays_the_original_response(): void
    {
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'abc'])->assertStatus(201);

        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'abc'])
            ->assertStatus(201)
            ->assertJson(['id' => 1])
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count, 'The route should not have executed a second time.');
    }

    public function test_same_key_with_a_different_payload_is_rejected(): void
    {
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'abc'])->assertStatus(201);

        $this->postJson('/orders', ['sku' => 'B'], ['Idempotency-Key' => 'abc'])
            ->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_requests_without_a_key_are_not_intercepted(): void
    {
        $this->postJson('/orders', ['sku' => 'A'])->assertStatus(201)->assertJson(['id' => 1]);
        $this->postJson('/orders', ['sku' => 'A'])->assertStatus(201)->assertJson(['id' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_unguarded_methods_are_not_intercepted(): void
    {
        $this->getJson('/orders', ['Idempotency-Key' => 'abc'])->assertJson(['id' => 1]);
        $this->getJson('/orders', ['Idempotency-Key' => 'abc'])->assertJson(['id' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_server_errors_are_not_cached_and_can_be_retried(): void
    {
        $this->postJson('/boom', [], ['Idempotency-Key' => 'abc'])->assertStatus(500);
        $this->postJson('/boom', [], ['Idempotency-Key' => 'abc'])->assertStatus(500);

        $this->assertSame(2, Counter::$count, 'A 5xx must not be replayed.');
    }

    public function test_an_in_flight_key_returns_409(): void
    {
        $repository = $this->app->make(IdempotencyRepository::class);
        $lock = $repository->lock(hash('sha256', 'abc'), 10);

        $this->assertTrue($lock->get());

        try {
            $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'abc'])
                ->assertStatus(409)
                ->assertHeader('Retry-After');

            $this->assertSame(0, Counter::$count);
        } finally {
            $lock->release();
        }
    }

    public function test_overlong_keys_are_rejected(): void
    {
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => str_repeat('x', 256)])
            ->assertStatus(400);

        $this->assertSame(0, Counter::$count);
    }

    public function test_keys_are_scoped_to_the_cache_store(): void
    {
        // Sanity check that the array store is the one actually backing replays.
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'abc'])->assertStatus(201);

        $store = $this->app->make(CacheFactory::class)->store('array');

        $this->assertNotNull($store->get('idempotency:' . hash('sha256', 'abc')));
    }
}
