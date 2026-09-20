<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Router;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\TestCase;

class RedirectBackTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $this->defineWebMiddlewareGroup($router);

        $router->middleware(['web', 'idempotency'])->group(function (Router $router): void {
            $router->post('/orders', fn () => response()->json(['id' => Counter::next()], 201));
            $router->post('/password', fn () => response()->json(['id' => Counter::next()], 201));
        });

        $router->middleware(['web', 'idempotency:required'])->post('/required', fn () => response()->json(['id' => Counter::next()], 201));

        $router->middleware('idempotency')->post('/api-orders', fn () => response()->json(['id' => Counter::next()], 201));
        $router->middleware('idempotency:required')->post('/api-required', fn () => response()->json(['id' => Counter::next()], 201));
    }

    public function test_an_in_progress_key_redirects_back_with_the_in_progress_message(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $repository = $this->app->make(IdempotencyRepository::class);
        $lock = $repository->lock($this->cacheKeyFor('busy'), 10);
        $this->assertTrue($lock->get());

        try {
            $response = $this->post('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'busy'])
                ->assertStatus(302);

            $response->assertSessionHasErrors(['idempotency' => 'Your previous submission is still being processed. Please wait a moment and try again.']);
            $response->assertSessionHasInput('sku', 'A');
            $response->assertSessionMissing('_idempotency_key');
        } finally {
            $lock->release();
        }

        $this->assertSame(0, Counter::$count);
    }

    public function test_a_conflicting_key_redirects_back_with_the_conflict_message(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'conflict'])->assertStatus(201);

        $this->post('/orders', ['sku' => 'B'], ['Idempotency-Key' => 'conflict'])
            ->assertStatus(302)
            ->assertSessionHasErrors(['idempotency' => 'This form was already submitted with different data. Please reload the page and try again.']);

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_missing_required_key_redirects_back_with_the_missing_key_message(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/required', ['sku' => 'A'])
            ->assertStatus(302)
            ->assertSessionHasErrors(['idempotency' => 'The form is missing its submission token. Please reload the page and try again.']);

        $this->assertSame(0, Counter::$count);
    }

    public function test_an_overlong_key_redirects_back_with_the_invalid_key_message(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/orders', ['sku' => 'A'], ['Idempotency-Key' => str_repeat('x', 256)])
            ->assertStatus(302)
            ->assertSessionHasErrors(['idempotency' => 'The form submission token is invalid. Please reload the page and try again.']);

        $this->assertSame(0, Counter::$count);
    }

    public function test_the_same_four_cases_on_an_api_route_still_throw(): void
    {
        $repository = $this->app->make(IdempotencyRepository::class);
        $lock = $repository->lock($this->cacheKeyFor('api-busy'), 10);
        $this->assertTrue($lock->get());

        try {
            $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'api-busy'])->assertStatus(409);
        } finally {
            $lock->release();
        }

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => 'api-conflict'])->assertStatus(201);
        $this->postJson('/api-orders', ['sku' => 'B'], ['Idempotency-Key' => 'api-conflict'])->assertStatus(422);

        $this->postJson('/api-required', ['sku' => 'A'])->assertStatus(400);

        $this->postJson('/api-orders', ['sku' => 'A'], ['Idempotency-Key' => str_repeat('x', 256)])->assertStatus(400);

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_web_request_expecting_json_throws_instead_of_redirecting(): void
    {
        $this->post('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'json-conflict'])->assertStatus(201);

        $this->post('/orders', ['sku' => 'B'], [
            'Idempotency-Key' => 'json-conflict',
            'Accept' => 'application/json',
        ])->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_disabling_redirect_back_throws_even_with_a_session(): void
    {
        config(['idempotency.redirect_back' => false]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'disabled'])->assertStatus(201);

        $this->post('/orders', ['sku' => 'B'], ['Idempotency-Key' => 'disabled'])->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_the_spanish_conflict_message_is_used_when_the_locale_is_spanish(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->app->setLocale('es');

        $this->post('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'es-conflict'])->assertStatus(201);

        $this->post('/orders', ['sku' => 'B'], ['Idempotency-Key' => 'es-conflict'])
            ->assertStatus(302)
            ->assertSessionHasErrors(['idempotency' => 'Este formulario ya se envió con otros datos. Recarga la página y vuelve a intentarlo.']);

        $this->assertSame(1, Counter::$count);
    }

    public function test_with_input_never_flashes_the_password(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/password', ['sku' => 'A'], ['Idempotency-Key' => 'pw'])->assertStatus(201);

        $response = $this->post('/password', ['sku' => 'B', 'password' => 'secret'], ['Idempotency-Key' => 'pw'])
            ->assertStatus(302);

        $response->assertSessionHasInput('sku', 'B');
        $response->assertSessionMissingInput('password');

        $this->assertSame(1, Counter::$count);
    }

    public function test_the_dont_flash_list_excludes_every_sensitive_field_and_the_configured_input_field(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        config(['idempotency.input' => 'token']);

        $this->post('/password', ['sku' => 'A'], ['Idempotency-Key' => 'dontflash'])->assertStatus(201);

        $response = $this->post('/password', [
            'sku' => 'B',
            'password' => 'secret',
            'password_confirmation' => 'secret',
            'current_password' => 'old-secret',
            'token' => 'field-value',
        ], ['Idempotency-Key' => 'dontflash'])->assertStatus(302);

        $response->assertSessionHasInput('sku', 'B');
        $response->assertSessionMissingInput(['password', 'password_confirmation', 'current_password', 'token']);

        $this->assertSame(1, Counter::$count);
    }
}
