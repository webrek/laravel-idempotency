<?php

namespace Webrek\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Webrek\Idempotency\Exceptions\ConcurrentRequestException;

class ConcurrentRequestExceptionTest extends TestCase
{
    public function test_the_retry_after_header_is_a_string(): void
    {
        $exception = new ConcurrentRequestException(5);

        $this->assertSame(['Retry-After' => '5'], $exception->getHeaders());
    }
}
