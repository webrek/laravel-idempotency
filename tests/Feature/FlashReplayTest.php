<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\TestCase;

class FlashReplayTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $this->defineWebMiddlewareGroup($router);

        $router->middleware(['web', 'idempotency'])->group(function (Router $router): void {
            $router->post('/submit', function (Request $request) {
                Counter::next();

                if ($request->input('sku') !== 'good') {
                    return redirect('/form')->withErrors(['sku' => 'The sku field is required.'])->withInput();
                }

                return redirect('/form')->with('status', 'Order created');
            });

            $router->get('/form', fn () => response()->json([
                'errors' => session('errors')?->all(),
                'old_sku' => old('sku'),
                'status' => session('status'),
            ]));
        });

        $router->middleware('idempotency')->post('/api-submit', fn () => response()->json(['id' => Counter::next()], 201));
    }

    public function test_a_replayed_validation_redirect_still_shows_the_error_and_old_input(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/submit', ['sku' => 'bad'], ['Idempotency-Key' => 'k1'])
            ->assertStatus(302)
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->post('/submit', ['sku' => 'bad'], ['Idempotency-Key' => 'k1'])
            ->assertStatus(302)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->get('/form')->assertExactJson([
            'errors' => ['The sku field is required.'],
            'old_sku' => 'bad',
            'status' => null,
        ]);

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_replayed_success_redirect_still_shows_the_status(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/submit', ['sku' => 'good'], ['Idempotency-Key' => 'k2'])
            ->assertStatus(302)
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->post('/submit', ['sku' => 'good'], ['Idempotency-Key' => 'k2'])
            ->assertStatus(302)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->get('/form')->assertExactJson([
            'errors' => null,
            'old_sku' => null,
            'status' => 'Order created',
        ]);

        $this->assertSame(1, Counter::$count);
    }

    public function test_disabling_replay_flash_does_not_re_flash_the_replay(): void
    {
        config(['idempotency.replay_flash' => false]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/submit', ['sku' => 'good'], ['Idempotency-Key' => 'k3'])->assertStatus(302);

        $this->post('/submit', ['sku' => 'good'], ['Idempotency-Key' => 'k3'])
            ->assertStatus(302)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->get('/form')->assertExactJson([
            'errors' => null,
            'old_sku' => null,
            'status' => null,
        ]);

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_non_session_route_replays_without_erroring(): void
    {
        $this->postJson('/api-submit', [], ['Idempotency-Key' => 'k4'])->assertStatus(201);

        $this->postJson('/api-submit', [], ['Idempotency-Key' => 'k4'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }
}
