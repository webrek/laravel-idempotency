<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\Support\RecordingRepository;
use Webrek\Idempotency\Tests\Support\User;
use Webrek\Idempotency\Tests\TestCase;

/**
 * `EnsureIdempotency::config($key, $default)` only falls back to its
 * hard-coded default when the key is *absent* from the `idempotency` config
 * array; a present value (even a "normal" one) always wins. These tests drop
 * keys from the config array with `Arr::except` so the middleware's own
 * defaults are what gets exercised, instead of the values already sitting in
 * config/idempotency.php.
 */
class DefaultsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('idempotency')->group(function (Router $router): void {
            $router->post('/orders', fn () => response()->json(['id' => Counter::next()], 201));

            $router->post('/redirect', function () {
                Counter::next();

                return redirect('/done');
            });

            $router->post('/big', function () {
                Counter::next();

                return response()->make(str_repeat('x', 2048), 201);
            });

            $router->post('/e408', function () {
                Counter::next();
                abort(408);
            });

            $router->post('/e425', function () {
                Counter::next();
                abort(425);
            });

            $router->post('/e429', function () {
                Counter::next();
                abort(429);
            });

            $router->post('/e404', function () {
                Counter::next();
                abort(404);
            });
        });
    }

    protected function withoutConfigKeys(array $keys): void
    {
        config(['idempotency' => Arr::except(config('idempotency'), $keys)]);
    }

    public function test_default_ttl_and_lock_timeout_are_used_when_absent_from_config(): void
    {
        $this->withoutConfigKeys(['ttl', 'lock_timeout']);

        $recorder = new RecordingRepository($this->app->make(IdempotencyRepository::class));
        $this->app->instance(IdempotencyRepository::class, $recorder);

        $this->postJson('/orders', [], ['Idempotency-Key' => 'defaults'])->assertStatus(201);

        $this->assertSame(86400, $recorder->lastPutTtl);
        $this->assertSame(10, $recorder->lastLockSeconds);
    }

    public function test_default_never_replay_status_codes_are_not_stored_but_others_are(): void
    {
        $this->withoutConfigKeys(['never_replay_status_codes']);

        $this->postJson('/e408', [], ['Idempotency-Key' => 'k408'])->assertStatus(408);
        $this->postJson('/e408', [], ['Idempotency-Key' => 'k408'])->assertStatus(408);
        $this->assertSame(2, Counter::$count, '408 must not be stored by default.');

        $this->postJson('/e425', [], ['Idempotency-Key' => 'k425'])->assertStatus(425);
        $this->postJson('/e425', [], ['Idempotency-Key' => 'k425'])->assertStatus(425);
        $this->assertSame(4, Counter::$count, '425 must not be stored by default.');

        $this->postJson('/e429', [], ['Idempotency-Key' => 'k429'])->assertStatus(429);
        $this->postJson('/e429', [], ['Idempotency-Key' => 'k429'])->assertStatus(429);
        $this->assertSame(6, Counter::$count, '429 must not be stored by default.');

        $this->postJson('/e404', [], ['Idempotency-Key' => 'k404'])->assertStatus(404);
        $this->postJson('/e404', [], ['Idempotency-Key' => 'k404'])->assertStatus(404);
        $this->assertSame(7, Counter::$count, '404 is not in the never-replay list, so it is stored and replayed.');
    }

    public function test_default_max_body_size_allows_large_bodies_to_be_stored(): void
    {
        $this->withoutConfigKeys(['max_body_size']);

        $this->post('/big', [], ['Idempotency-Key' => 'big'])->assertStatus(201);

        $this->post('/big', [], ['Idempotency-Key' => 'big'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_default_persist_headers_still_replays_the_location_header(): void
    {
        $this->withoutConfigKeys(['persist_headers']);

        $this->postJson('/redirect', [], ['Idempotency-Key' => 'r1'])->assertStatus(302);

        $this->postJson('/redirect', [], ['Idempotency-Key' => 'r1'])
            ->assertStatus(302)
            ->assertHeader('Location', 'http://localhost/done');
    }

    public function test_an_empty_persist_headers_array_still_replays_the_location_header(): void
    {
        config(['idempotency.persist_headers' => []]);

        $this->postJson('/redirect', [], ['Idempotency-Key' => 'r2'])->assertStatus(302);

        $this->postJson('/redirect', [], ['Idempotency-Key' => 'r2'])
            ->assertStatus(302)
            ->assertHeader('Location', 'http://localhost/done');
    }

    public function test_default_scope_by_user_prevents_collisions_between_users(): void
    {
        $this->withoutConfigKeys(['scope_by_user']);

        Schema::create('users', fn (Blueprint $table) => $table->id());

        $a = User::create();
        $b = User::create();

        $this->actingAs($a)->postJson('/orders', [], ['Idempotency-Key' => 'k'])->assertJson(['id' => 1]);
        $this->actingAs($b)->postJson('/orders', [], ['Idempotency-Key' => 'k'])->assertJson(['id' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_default_require_key_allows_keyless_requests(): void
    {
        $this->withoutConfigKeys(['require_key']);

        $this->postJson('/orders', [])->assertStatus(201);

        $this->assertSame(1, Counter::$count);
    }

    public function test_default_max_key_length_is_255_characters(): void
    {
        $this->withoutConfigKeys(['max_key_length']);

        $this->postJson('/orders', [], ['Idempotency-Key' => str_repeat('x', 255)])->assertStatus(201);
        $this->postJson('/orders', [], ['Idempotency-Key' => str_repeat('x', 256)])->assertStatus(400);
    }

    public function test_lowercase_methods_configuration_still_matches_the_request_method(): void
    {
        config(['idempotency.methods' => ['post']]);

        $this->postJson('/orders', [], ['Idempotency-Key' => 'lc'])->assertStatus(201);

        $this->postJson('/orders', [], ['Idempotency-Key' => 'lc'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_multibyte_key_is_measured_in_characters_not_bytes(): void
    {
        // 255 multibyte characters is 510 bytes; strlen() would wrongly reject it.
        $this->postJson('/orders', [], ['Idempotency-Key' => str_repeat('é', 255)])->assertStatus(201);
    }
}
