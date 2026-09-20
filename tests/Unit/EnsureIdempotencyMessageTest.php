<?php

namespace Webrek\Idempotency\Tests\Unit;

use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webrek\Idempotency\Http\Middleware\EnsureIdempotency;
use Webrek\Idempotency\Tests\TestCase;

/**
 * `EnsureIdempotency::message()` is only ever called from `reject()`, which
 * — in production — always passes one of the four known idempotency
 * exceptions. The fallback to the raw exception message for anything else is
 * unreachable through the public HTTP surface, so it is exercised directly
 * via reflection instead.
 */
class EnsureIdempotencyMessageTest extends TestCase
{
    public function test_it_falls_back_to_the_exceptions_own_message_for_an_unmapped_exception(): void
    {
        $middleware = $this->app->make(EnsureIdempotency::class);

        $method = new ReflectionMethod($middleware, 'message');
        $method->setAccessible(true);

        $exception = new HttpException(400, 'a custom message');

        $this->assertSame('a custom message', $method->invoke($middleware, $exception));
    }
}
