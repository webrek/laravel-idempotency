<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Routing\Router;
use InvalidArgumentException;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\TestCase;

class MiddlewareOptionsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('/required-short', fn () => response()->json(['id' => Counter::next()], 201))
            ->middleware('idempotency:required,1');

        $router->post('/weird-option', fn () => response()->json(['id' => Counter::next()], 201))
            ->middleware('idempotency:5abc');
    }

    public function test_the_required_option_does_not_swallow_a_trailing_ttl_option(): void
    {
        $this->postJson('/required-short', [])->assertStatus(400);

        $this->postJson('/required-short', [], ['Idempotency-Key' => 'rs'])
            ->assertStatus(201)
            ->assertJson(['id' => 1]);

        // If "required" incorrectly stopped option parsing, the trailing TTL
        // of 1 second would never be applied and this would replay instead.
        $this->travel(2)->seconds();

        $this->postJson('/required-short', [], ['Idempotency-Key' => 'rs'])
            ->assertStatus(201)
            ->assertJson(['id' => 2]);

        $this->assertSame(2, Counter::$count);
    }

    public function test_a_leading_digit_option_that_is_not_purely_numeric_is_rejected(): void
    {
        $this->withoutExceptionHandling();

        $this->expectException(InvalidArgumentException::class);

        // (int) '5abc' === 5, but ctype_digit('5abc') is false; the option
        // must still be rejected rather than silently treated as TTL 5.
        $this->postJson('/weird-option', [], ['Idempotency-Key' => 'z']);
    }
}
