<?php

namespace Webrek\Idempotency\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ConcurrentRequestException extends HttpException
{
    public function __construct(int $retryAfter = 1)
    {
        parent::__construct(
            409,
            'A request with this idempotency key is already in progress.',
            null,
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
