<?php

namespace Webrek\Idempotency\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MissingIdempotencyKeyException extends HttpException
{
    public function __construct(string $header)
    {
        parent::__construct(400, sprintf('This endpoint requires an "%s" header.', $header));
    }
}
