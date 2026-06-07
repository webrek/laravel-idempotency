<?php

namespace Webrek\Idempotency\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class IdempotencyConflictException extends HttpException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'This idempotency key was already used for a request with a different payload.',
        );
    }
}
