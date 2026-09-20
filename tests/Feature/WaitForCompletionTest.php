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
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $this->defineWebMiddlewareGroup($router);

        $router->middleware(['web', 'idempotency'])->post('/form', fn () => response()->json(['id' => Counter::next()], 201));
        $router->middleware('idempotency')->post('/api-orders', fn () => response()->json(['id' => Counter::next()], 201));
    }

    private function useScriptedRepository(ScriptedLock $lock, ?StoredResponse $afterBlock = null): void
    {
        $repository = new ScriptedRepository($this->app->make(IdempotencyRepository::class), $lock, $afterBlock);

        $this->app->instance(IdempotencyRepository::class, $repository);
    }

    public function test_a_successful_block_replays_the_response_found_on_the_second_check(): void
    {
        config(['idempotency.wait_for_completion' => 2]);

        // Mirrors EnsureIdempotency::fingerprint() for a form POST (empty raw
        // body, so it falls back to the normalised, JSON-encoded input) so
        // the scripted response the second `get()` returns is accepted as a
        // match instead of tripping the conflict check.
        $fingerprint = hash('sha256', implode('|', ['POST', '/api-orders', json_encode(['sku' => 'A'])]));

        $lock = new ScriptedLock(blockSucceeds: true);
        $stored = new StoredResponse(201, '{"id":99}', ['Content-Type' => 'application/json'], $fingerprint);
        $this->useScriptedRepository($lock, $stored);

        $this->post('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertJson(['id' => 99])
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertTrue($lock->blockCalled);
        $this->assertSame(0, Counter::$count);
    }

    public function test_a_successful_block_with_nothing_stored_executes_fresh(): void
    {
        config(['idempotency.wait_for_completion' => 2]);

        $lock = new ScriptedLock(blockSucceeds: true);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->assertTrue($lock->blockCalled);
        $this->assertSame(1, Counter::$count);
    }

    public function test_a_timed_out_block_returns_409_on_an_api_route(): void
    {
        config(['idempotency.wait_for_completion' => 2]);

        $lock = new ScriptedLock(blockSucceeds: false);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(409);

        $this->assertTrue($lock->blockCalled);
        $this->assertSame(0, Counter::$count);
    }

    public function test_a_timed_out_block_redirects_back_with_the_in_progress_message_on_a_web_route(): void
    {
        config(['idempotency.wait_for_completion' => 2]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $lock = new ScriptedLock(blockSucceeds: false);
        $this->useScriptedRepository($lock);

        $this->post('/form', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(302)
            ->assertSessionHasErrors(['idempotency' => 'Your previous submission is still being processed. Please wait a moment and try again.']);

        $this->assertTrue($lock->blockCalled);
        $this->assertSame(0, Counter::$count);
    }

    public function test_a_zero_wait_never_calls_block(): void
    {
        config(['idempotency.wait_for_completion' => 0]);

        $lock = new ScriptedLock(blockSucceeds: true);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(409);

        $this->assertFalse($lock->blockCalled);
        $this->assertSame(0, Counter::$count);
    }

    public function test_the_default_wait_for_completion_is_zero_and_never_blocks(): void
    {
        // Drop the key entirely so the middleware's own hard-coded default —
        // not the value shipped in config/idempotency.php — is exercised.
        config(['idempotency' => Arr::except(config('idempotency'), ['wait_for_completion'])]);

        $lock = new ScriptedLock(blockSucceeds: true);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(409);

        $this->assertFalse($lock->blockCalled);
        $this->assertSame(0, Counter::$count);
    }

    public function test_a_wait_of_exactly_one_second_still_blocks(): void
    {
        config(['idempotency.wait_for_completion' => 1]);

        $lock = new ScriptedLock(blockSucceeds: true);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->assertTrue($lock->blockCalled);
        $this->assertSame(1, Counter::$count);
    }

    public function test_a_non_integer_wait_for_completion_is_cast_to_an_int_before_blocking(): void
    {
        config(['idempotency.wait_for_completion' => '2']);

        $lock = new ScriptedLock(blockSucceeds: true);
        $this->useScriptedRepository($lock);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'k'])->assertStatus(201);

        $this->assertSame(2, $lock->blockSeconds);
    }
}
