<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Webrek\Idempotency\Events\IdempotentReplay;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\TestCase;

class ReplayEventAndTtlTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('idempotency')->post('/orders', fn () => response()->json(['id' => Counter::next()], 201));
        $router->middleware('idempotency:1')->post('/short', fn () => response()->json(['n' => Counter::next()], 201));
    }

    public function test_it_fires_an_event_on_replay(): void
    {
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])->assertStatus(201);

        Event::fake([IdempotentReplay::class]);

        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        Event::assertDispatched(IdempotentReplay::class, fn (IdempotentReplay $e): bool => $e->key === 'k' && $e->response->status === 201);
    }

    public function test_a_per_route_ttl_expires_the_stored_response(): void
    {
        $this->postJson('/short', [], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['n' => 1]);

        $this->travel(2)->seconds();

        $this->postJson('/short', [], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['n' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_within_the_ttl_the_response_is_replayed(): void
    {
        $this->postJson('/short', [], ['Idempotency-Key' => 'k'])->assertJson(['n' => 1]);

        $this->postJson('/short', [], ['Idempotency-Key' => 'k'])
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJson(['n' => 1]);

        $this->assertSame(1, Counter::$count);
    }
}
