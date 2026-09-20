<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\StoredResponse;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\Support\ScriptedLock;
use Webrek\Idempotency\Tests\Support\ScriptedRepository;
use Webrek\Idempotency\Tests\TestCase;

class WaitForCompletionTest extends TestCase
{
    private ScriptedRepository $repository;

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $this->defineWebMiddlewareGroup($router);

        $router->middleware(['web', 'idempotency'])->post('/form', fn () => response()->json(['id' => Counter::next()], 201));
        $router->middleware('idempotency')->post('/api-orders', fn () => response()->json(['id' => Counter::next()], 201));
    }

    private function useScriptedRepository(ScriptedLock $lock, ?StoredResponse $afterWait = null): void
    {
        $this->repository = new ScriptedRepository($this->app->make(IdempotencyRepository::class), $lock, $afterWait);

        $this->app->instance(IdempotencyRepository::class, $this->repository);
    }

    private function storedFor(string $path, array $input): StoredResponse
    {
        // Mirrors EnsureIdempotency::fingerprint() for a form POST (empty raw
        // body, so it falls back to the normalised, JSON-encoded input).
        $fingerprint = hash('sha256', implode('|', ['POST', $path, json_encode($input)]));

        return new StoredResponse(201, '{"id":99}', ['Content-Type' => 'application/json'], $fingerprint);
    }

    public function test_a_response_stored_while_waiting_is_replayed_without_taking_the_lock(): void
    {
        config(['idempotency.wait_for_completion' => 2]);

        $lock = new ScriptedLock([false]);
        $this->useScriptedRepository($lock, $this->storedFor('/api-orders', ['sku' => 'A']));

        $this->post('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['id' => 99])
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, $lock->getCalls, 'The lock must only be tried once; a replay needs no lock.');
        $this->assertSame(2, $this->repository->getCalls);
        $this->assertFalse($lock->blockCalled);
        $this->assertSame(0, Counter::$count);
    }

    public function test_a_lock_that_frees_up_with_nothing_stored_executes_fresh(): void
    {
        config(['idempotency.wait_for_completion' => 2]);

        $lock = new ScriptedLock([false, true]);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->assertSame(2, $lock->getCalls);
        $this->assertSame(1, Counter::$count);
    }

    public function test_a_timed_out_wait_returns_409_on_an_api_route(): void
    {
        config(['idempotency.wait_for_completion' => 1]);

        $lock = new ScriptedLock([false]);
        $this->useScriptedRepository($lock);

        $started = microtime(true);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(409);

        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $started);
        $this->assertGreaterThan(2, $lock->getCalls, 'The wait must keep polling until the deadline.');
        $this->assertLessThan(30, $this->repository->getCalls, 'Polling must pause between checks, not spin.');
        $this->assertSame(0, Counter::$count);
    }

    public function test_a_timed_out_wait_redirects_back_with_the_in_progress_message_on_a_web_route(): void
    {
        config(['idempotency.wait_for_completion' => 1]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $lock = new ScriptedLock([false]);
        $this->useScriptedRepository($lock);

        $this->post('/form', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(302)
            ->assertSessionHasErrors(['idempotency' => 'Your previous submission is still being processed. Please wait a moment and try again.']);

        $this->assertSame(0, Counter::$count);
    }

    public function test_a_zero_wait_rejects_immediately(): void
    {
        config(['idempotency.wait_for_completion' => 0]);

        $lock = new ScriptedLock([false, true]);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(409);

        $this->assertSame(1, $lock->getCalls);
        $this->assertSame(1, $this->repository->getCalls);
        $this->assertSame(0, Counter::$count);
    }

    public function test_the_default_wait_for_completion_is_zero(): void
    {
        // Drop the key entirely so the middleware's own hard-coded default —
        // not the value shipped in config/idempotency.php — is exercised.
        config(['idempotency' => Arr::except(config('idempotency'), ['wait_for_completion'])]);

        $lock = new ScriptedLock([false, true]);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(409);

        $this->assertSame(1, $lock->getCalls);
        $this->assertSame(0, Counter::$count);
    }

    public function test_a_wait_of_exactly_one_second_still_polls(): void
    {
        config(['idempotency.wait_for_completion' => 1]);

        $lock = new ScriptedLock([false, true]);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->assertSame(2, $lock->getCalls);
        $this->assertSame(1, Counter::$count);
    }

    public function test_a_numeric_string_wait_still_polls(): void
    {
        config(['idempotency.wait_for_completion' => '2']);

        $lock = new ScriptedLock([false, true]);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])->assertStatus(201);

        $this->assertSame(2, $lock->getCalls);
    }
}
