<?php

namespace Webrek\Idempotency\Events;

use Illuminate\Http\Request;
use Throwable;

/**
 * Fired when the store could not be written after the request already ran:
 * either persisting the response ("put") or releasing the key's lock
 * ("release") threw. The fresh response is still returned to the client, the
 * exception is reported, and the next retry of the same key will execute
 * again because nothing was stored — listen to this to alert on it.
 */
class IdempotencyStorageFailed
{
    /**
     * @param  'put'|'release'  $operation
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $key,
        public readonly Request $request,
        public readonly Throwable $exception,
    ) {}
}
