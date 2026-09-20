<?php

namespace Webrek\Idempotency\Tests\Unit;

use Illuminate\Http\Request;
use ReflectionMethod;
use Webrek\Idempotency\Http\Middleware\EnsureIdempotency;
use Webrek\Idempotency\Tests\TestCase;

/**
 * Exercised via reflection against a hand-built Request, bypassing the HTTP
 * kernel entirely: Laravel's own global `TrimStrings` middleware already
 * trims form input before a real request reaches the application, which
 * would otherwise mask whether `resolveKeyFromInput()` trims on its own.
 */
class EnsureIdempotencyResolveKeyFromInputTest extends TestCase
{
    public function test_a_whitespace_padded_field_value_is_trimmed(): void
    {
        $middleware = $this->app->make(EnsureIdempotency::class);

        $request = Request::create('/orders', 'POST', ['_idempotency_key' => '  padded  ']);

        $method = new ReflectionMethod($middleware, 'resolveKeyFromInput');
        $method->setAccessible(true);

        $this->assertSame('padded', $method->invoke($middleware, $request));
    }
}
