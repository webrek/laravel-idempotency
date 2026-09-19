<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\Tests\Support\Admin;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\Support\User;
use Webrek\Idempotency\Tests\TestCase;

class V130RegressionTest extends TestCase
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

            $router->post('/created', function () {
                $id = Counter::next();

                return response()->json(['id' => $id], 201)->header('Location', "/orders/{$id}");
            });

            $router->post('/upload', fn () => response()->json(['id' => Counter::next()], 201));

            $router->post('/big', function () {
                Counter::next();

                return response()->make(str_repeat('x', 200), 201);
            });

            $router->post('/limited', function () {
                Counter::next();
                abort(429);
            });
        });

        $router->post('/bad-option', fn () => response()->json(['id' => Counter::next()], 201))
            ->middleware('idempotency:0');

        $router->post('/bogus-option', fn () => response()->json(['id' => Counter::next()], 201))
            ->middleware('idempotency:bogus');

        $router->post('/required1', fn () => response()->json(['id' => Counter::next()], 201))
            ->middleware('idempotency:required');

        $router->post('/required2', fn () => response()->json(['id' => Counter::next()], 201))
            ->middleware('idempotency:3600,required');

        $router->post('/required3', fn () => response()->json(['id' => Counter::next()], 201))
            ->middleware('idempotency:,required');
    }

    public function test_replayed_redirect_keeps_the_location_header(): void
    {
        $this->postJson('/redirect', [], ['Idempotency-Key' => 'red'])
            ->assertStatus(302)
            ->assertHeader('Location', 'http://localhost/done')
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->postJson('/redirect', [], ['Idempotency-Key' => 'red'])
            ->assertStatus(302)
            ->assertHeader('Location', 'http://localhost/done')
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_replayed_created_response_keeps_a_controller_set_location_header(): void
    {
        $this->postJson('/created', [], ['Idempotency-Key' => 'loc'])
            ->assertStatus(201)
            ->assertHeader('Location', '/orders/1');

        $this->postJson('/created', [], ['Idempotency-Key' => 'loc'])
            ->assertStatus(201)
            ->assertHeader('Location', '/orders/1')
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_query_string_is_part_of_the_fingerprint(): void
    {
        $this->postJson('/orders?x=1', [], ['Idempotency-Key' => 'q'])
            ->assertStatus(201)
            ->assertJson(['id' => 1]);

        $this->postJson('/orders?x=2', [], ['Idempotency-Key' => 'q'])
            ->assertStatus(422);

        $this->postJson('/orders?x=1', [], ['Idempotency-Key' => 'q'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJson(['id' => 1]);

        $this->assertSame(1, Counter::$count);
    }

    public function test_form_encoded_body_is_part_of_the_fingerprint(): void
    {
        $this->post('/orders', ['a' => 1], ['Idempotency-Key' => 'form'])->assertStatus(201);

        $this->post('/orders', ['a' => 2], ['Idempotency-Key' => 'form'])->assertStatus(422);

        $this->post('/orders', ['a' => 1], ['Idempotency-Key' => 'form'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_nested_form_input_order_does_not_affect_the_fingerprint(): void
    {
        $this->post('/orders', ['a' => ['y' => 1, 'x' => 2]], ['Idempotency-Key' => 'nested'])
            ->assertStatus(201);

        $this->post('/orders', ['a' => ['x' => 2, 'y' => 1]], ['Idempotency-Key' => 'nested'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_multipart_uploads_are_part_of_the_fingerprint(): void
    {
        $this->post('/upload', ['file' => UploadedFile::fake()->create('a.txt', 1)], ['Idempotency-Key' => 'up'])
            ->assertStatus(201);

        $this->post('/upload', ['file' => UploadedFile::fake()->create('a.txt', 1)], ['Idempotency-Key' => 'up'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->post('/upload', ['file' => UploadedFile::fake()->createWithContent('a.txt', 'other')], ['Idempotency-Key' => 'up'])
            ->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_guest_key_does_not_collide_with_a_users_scoped_key(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());

        $user = User::create();

        $this->postJson('/orders', [], ['Idempotency-Key' => "abc|u:{$user->id}"])
            ->assertJson(['id' => 1]);

        $this->actingAs($user)->postJson('/orders', [], ['Idempotency-Key' => 'abc'])
            ->assertJson(['id' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_different_user_classes_with_the_same_id_do_not_collide(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('admins', fn (Blueprint $table) => $table->id());

        $user = User::create();
        $admin = Admin::create();

        $this->actingAs($user)->postJson('/orders', [], ['Idempotency-Key' => 'k'])
            ->assertJson(['id' => 1]);

        $this->actingAs($admin)->postJson('/orders', [], ['Idempotency-Key' => 'k'])
            ->assertJson(['id' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_transient_client_errors_are_not_stored_by_default(): void
    {
        $this->postJson('/limited', [], ['Idempotency-Key' => 'lim'])->assertStatus(429);
        $this->postJson('/limited', [], ['Idempotency-Key' => 'lim'])->assertStatus(429);

        $this->assertSame(2, Counter::$count);
    }

    public function test_transient_client_errors_can_be_allowlisted_for_replay(): void
    {
        config(['idempotency.replay_status_codes' => [429]]);

        $this->postJson('/limited', [], ['Idempotency-Key' => 'lim2'])->assertStatus(429);

        $this->postJson('/limited', [], ['Idempotency-Key' => 'lim2'])
            ->assertStatus(429)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_oversized_bodies_are_not_stored(): void
    {
        config(['idempotency.max_body_size' => 10]);

        $this->post('/big', [], ['Idempotency-Key' => 'big'])->assertStatus(201);
        $this->post('/big', [], ['Idempotency-Key' => 'big'])->assertStatus(201);

        $this->assertSame(2, Counter::$count);
    }

    public function test_a_zero_max_body_size_disables_the_limit(): void
    {
        config(['idempotency.max_body_size' => 0]);

        $this->post('/big', [], ['Idempotency-Key' => 'big0'])->assertStatus(201);

        $this->post('/big', [], ['Idempotency-Key' => 'big0'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_zero_route_ttl_option_is_rejected(): void
    {
        $this->withoutExceptionHandling();

        $this->expectException(InvalidArgumentException::class);

        $this->postJson('/bad-option', [], ['Idempotency-Key' => 'z']);
    }

    public function test_an_unrecognised_middleware_option_is_rejected(): void
    {
        $this->withoutExceptionHandling();

        $this->expectException(InvalidArgumentException::class);

        $this->postJson('/bogus-option', [], ['Idempotency-Key' => 'z']);
    }

    public function test_a_zero_lock_timeout_is_rejected_only_once_a_key_is_present(): void
    {
        config(['idempotency.lock_timeout' => 0]);

        // Keyless pass-through never reaches the repository, so it must not validate config.
        $this->postJson('/orders', ['sku' => 'A'])->assertStatus(201);

        $this->withoutExceptionHandling();

        $this->expectException(InvalidArgumentException::class);

        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'z']);
    }

    public function test_required_option_forces_a_key_regardless_of_configuration(): void
    {
        $this->postJson('/required1', [])->assertStatus(400);
        $this->postJson('/required1', [], ['Idempotency-Key' => 'r1'])->assertStatus(201);

        $this->postJson('/required2', [])->assertStatus(400);
        $this->postJson('/required2', [], ['Idempotency-Key' => 'r2'])->assertStatus(201);

        $this->postJson('/required3', [])->assertStatus(400);
        $this->postJson('/required3', [], ['Idempotency-Key' => 'r3'])->assertStatus(201);
    }

    public function test_an_expired_manual_lock_does_not_block_a_fresh_request(): void
    {
        $repository = $this->app->make(IdempotencyRepository::class);
        $lock = $repository->lock($this->cacheKeyFor('expiring'), 4);

        $this->assertTrue($lock->get());

        $this->travel(5)->seconds();

        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'expiring'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->assertSame(1, Counter::$count);
    }
}
