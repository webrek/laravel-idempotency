<?php

namespace Webrek\Idempotency\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidIdempotencyKeyException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(400, $message);
    }
}
