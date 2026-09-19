<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Routing\Router;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\TestCase;

class ResponseCacheabilityTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('idempotency')->group(function (Router $router): void {
            $router->post('/file', function () {
                Counter::next();

                return response()->file(__FILE__);
            });

            $router->post('/exact', function () {
                Counter::next();

                return response()->make(str_repeat('x', 200), 201);
            });
        });
    }

    public function test_binary_file_responses_are_never_stored(): void
    {
        $this->post('/file', [], ['Idempotency-Key' => 'bf'])->assertStatus(200);
        $this->post('/file', [], ['Idempotency-Key' => 'bf'])->assertStatus(200);

        $this->assertSame(2, Counter::$count);
    }

    public function test_a_response_body_exactly_at_the_max_body_size_limit_is_still_stored(): void
    {
        config(['idempotency.max_body_size' => 200]);

        $this->post('/exact', [], ['Idempotency-Key' => 'ex'])->assertStatus(201);

        $this->post('/exact', [], ['Idempotency-Key' => 'ex'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }
}
