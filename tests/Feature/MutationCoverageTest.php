<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\Support\User;
use Webrek\Idempotency\Tests\TestCase;

class MutationCoverageTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('idempotency')->group(function (Router $router): void {
            $router->post('/orders', fn () => response()->json(['id' => Counter::next()], 201));
            $router->match(['post', 'put'], '/multi', fn () => response()->json(['id' => Counter::next()], 201));
            $router->post('/accepted', fn () => response()->json(['n' => Counter::next()], 200));
            $router->post('/stream', function () {
                Counter::next();   // runs whenever the route executes (not on replay)

                return response()->stream(fn () => print ('x'), 200);
            });
        });
    }

    public function test_concurrent_request_sets_retry_after_to_one(): void
    {
        $lock = $this->app->make(IdempotencyRepository::class)->lock(hash('sha256', 'k'), 10);
        $this->assertTrue($lock->get());

        try {
            $this->postJson('/orders', [], ['Idempotency-Key' => 'k'])
                ->assertStatus(409)
                ->assertHeader('Retry-After', '1');
        } finally {
            $lock->release();
        }
    }

    public function test_require_key_rejects_keyless_requests(): void
    {
        config(['idempotency.require_key' => true]);

        $this->postJson('/orders', [])->assertStatus(400);
        $this->assertSame(0, Counter::$count);
    }

    public function test_fingerprint_distinguishes_the_http_method(): void
    {
        $this->postJson('/multi', ['a' => 1], ['Idempotency-Key' => 'k'])->assertStatus(201);

        // Same key, same path and body, different method -> different fingerprint -> conflict.
        $this->putJson('/multi', ['a' => 1], ['Idempotency-Key' => 'k'])->assertStatus(422);
    }

    public function test_keys_are_scoped_per_authenticated_user(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());

        $a = User::create();
        $b = User::create();

        $this->actingAs($a)->postJson('/orders', [], ['Idempotency-Key' => 'k'])->assertJson(['id' => 1]);
        // Different user, same key -> not a replay of the first user's response.
        $this->actingAs($b)->postJson('/orders', [], ['Idempotency-Key' => 'k'])->assertJson(['id' => 2]);
        // First user again -> their own replay.
        $this->actingAs($a)->postJson('/orders', [], ['Idempotency-Key' => 'k'])
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJson(['id' => 1]);
    }

    public function test_a_key_at_the_maximum_length_is_accepted(): void
    {
        $this->postJson('/orders', [], ['Idempotency-Key' => str_repeat('x', 255)])->assertStatus(201);
    }

    public function test_streamed_responses_are_not_cached(): void
    {
        $this->post('/stream', [], ['Idempotency-Key' => 'ks'])->assertStatus(200);
        $this->post('/stream', [], ['Idempotency-Key' => 'ks'])->assertStatus(200);

        $this->assertSame(2, Counter::$count, 'A streamed response must not be replayed.');
    }

    public function test_replay_status_codes_allowlist_is_honoured(): void
    {
        config(['idempotency.replay_status_codes' => [201]]);

        // 200 is not in the allowlist -> re-executed each time.
        $this->postJson('/accepted', [], ['Idempotency-Key' => 'a'])->assertJson(['n' => 1]);
        $this->postJson('/accepted', [], ['Idempotency-Key' => 'a'])->assertJson(['n' => 2]);

        // 201 is in the allowlist -> replayed.
        $this->postJson('/orders', [], ['Idempotency-Key' => 'b'])->assertJson(['id' => 3]);
        $this->postJson('/orders', [], ['Idempotency-Key' => 'b'])
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJson(['id' => 3]);
    }

    public function test_keys_are_trimmed(): void
    {
        $this->postJson('/orders', [], ['Idempotency-Key' => '  k  '])->assertStatus(201)->assertJson(['id' => 1]);

        // The padded key must resolve to the same stored response as the trimmed one.
        $this->postJson('/orders', [], ['Idempotency-Key' => 'k'])
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJson(['id' => 1]);
    }

    public function test_persisted_headers_are_replayed(): void
    {
        $this->postJson('/orders', [], ['Idempotency-Key' => 'k'])->assertStatus(201);

        $this->postJson('/orders', [], ['Idempotency-Key' => 'k'])
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertHeader('Content-Type', 'application/json');
    }
}
