<?php

namespace Webrek\Idempotency\Contracts;

use Illuminate\Contracts\Cache\Lock;
use Webrek\Idempotency\StoredResponse;

interface IdempotencyRepository
{
    /**
     * Fetch a previously stored response for the given (already hashed) key.
     */
    public function get(string $key): ?StoredResponse;

    /**
     * Persist a response for replay for $ttl seconds.
     */
    public function put(string $key, StoredResponse $response, int $ttl): void;

    /**
     * Drop a stored response, allowing the key to execute again.
     */
    public function forget(string $key): void;

    /**
     * Build the atomic lock guarding single execution of a key.
     */
    public function lock(string $key, int $seconds): Lock;
}
