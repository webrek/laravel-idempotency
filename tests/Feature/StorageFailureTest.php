<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\Events\IdempotencyStorageFailed;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\Support\FlakyRepository;
use Webrek\Idempotency\Tests\TestCase;

class StorageFailureTest extends TestCase
{
    private FlakyRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new FlakyRepository($this->app->make(IdempotencyRepository::class));
        $this->app->instance(IdempotencyRepository::class, $this->repository);
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('idempotency')->post('/orders', fn () => response()->json(['id' => Counter::next()], 201));
    }

    public function test_a_failed_put_still_returns_the_fresh_response_and_is_reported(): void
    {
        Exceptions::fake([RuntimeException::class]);
        Event::fake([IdempotencyStorageFailed::class]);

        $this->repository->failPut = true;

        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['id' => 1])
            ->assertHeader('Idempotency-Replayed', 'false');

        Exceptions::assertReported(RuntimeException::class);
        Event::assertDispatched(
            IdempotencyStorageFailed::class,
            fn (IdempotencyStorageFailed $e): bool => $e->operation === 'put'
                && $e->key === 'k'
                && $e->exception->getMessage() === 'cache unreachable during put',
        );

        // Nothing was stored, so a retry executes again (at-least-once fallback)...
        $this->repository->failPut = false;

        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['id' => 2])
            ->assertHeader('Idempotency-Replayed', 'false');

        // ...and once the store recovers, the key replays normally.
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['id' => 2])
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(2, Counter::$count);
    }

    public function test_a_failed_lock_release_does_not_mask_the_response(): void
    {
        Exceptions::fake([RuntimeException::class]);
        Event::fake([IdempotencyStorageFailed::class]);

        $this->repository->failRelease = true;

        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['id' => 1])
            ->assertHeader('Idempotency-Replayed', 'false');

        Exceptions::assertReported(RuntimeException::class);
        Event::assertDispatched(
            IdempotencyStorageFailed::class,
            fn (IdempotencyStorageFailed $e): bool => $e->operation === 'release' && $e->key === 'k',
        );

        // The response itself was stored, so the retry replays without needing the lock.
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['id' => 1])
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_storage_failures_are_not_reported_when_nothing_fails(): void
    {
        Exceptions::fake([RuntimeException::class]);
        Event::fake([IdempotencyStorageFailed::class]);

        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])->assertStatus(201);

        Exceptions::assertNothingReported();
        Event::assertNotDispatched(IdempotencyStorageFailed::class);
    }
}
