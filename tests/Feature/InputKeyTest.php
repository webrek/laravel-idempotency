<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Routing\Router;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\TestCase;

class InputKeyTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('idempotency')->post('/orders', fn () => response()->json(['id' => Counter::next()], 201));
    }

    public function test_a_form_field_key_replays_like_the_header(): void
    {
        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => 'field-key'])
            ->assertStatus(201)
            ->assertJson(['id' => 1]);

        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => 'field-key'])
            ->assertStatus(201)
            ->assertJson(['id' => 1])
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_the_header_wins_when_both_are_present_with_different_values(): void
    {
        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => 'field-1'], ['Idempotency-Key' => 'header-1'])
            ->assertStatus(201)
            ->assertJson(['id' => 1]);

        // The field value alone (no header) resolves to a different key, so
        // it does not replay the header-keyed entry above.
        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => 'field-1'])
            ->assertStatus(201)
            ->assertJson(['id' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_disabling_the_input_ignores_the_field(): void
    {
        config(['idempotency.input' => null]);

        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => 'field-key'])
            ->assertStatus(201)
            ->assertJson(['id' => 1]);

        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => 'field-key'])
            ->assertStatus(201)
            ->assertJson(['id' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_a_custom_field_name_is_honoured(): void
    {
        config(['idempotency.input' => 'token']);

        $this->post('/orders', ['sku' => 'A', 'token' => 'custom-key'])
            ->assertStatus(201)
            ->assertJson(['id' => 1]);

        $this->post('/orders', ['sku' => 'A', 'token' => 'custom-key'])
            ->assertStatus(201)
            ->assertJson(['id' => 1])
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_field_key_with_surrounding_whitespace_is_trimmed(): void
    {
        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => '  padded  '])
            ->assertStatus(201)
            ->assertJson(['id' => 1]);

        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => 'padded'])
            ->assertStatus(201)
            ->assertJson(['id' => 1])
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_an_overlong_field_key_is_rejected(): void
    {
        $this->post('/orders', ['sku' => 'A', '_idempotency_key' => str_repeat('x', 256)])
            ->assertStatus(400);

        $this->assertSame(0, Counter::$count);
    }
}
