<?php

namespace Webrek\Idempotency\Events;

use Illuminate\Http\Request;
use Webrek\Idempotency\StoredResponse;

/**
 * Fired when a stored response is replayed instead of re-executing the request.
 * Useful for metrics — how many retries you are absorbing — and auditing.
 */
class IdempotentReplay
{
    public function __construct(
        public readonly string $key,
        public readonly Request $request,
        public readonly StoredResponse $response,
    ) {}
}
