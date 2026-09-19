<?php

namespace Webrek\Idempotency\Tests\Support;

use Illuminate\Contracts\Cache\Lock;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\StoredResponse;

/**
 * Wraps the real repository so tests can observe the TTL and lock duration
 * the middleware actually passes through, instead of only the resulting
 * behaviour.
 */
final class RecordingRepository implements IdempotencyRepository
{
    public ?int $lastPutTtl = null;

    public ?int $lastLockSeconds = null;

    public function __construct(
        protected IdempotencyRepository $repository,
    ) {}

    public function get(string $key): ?StoredResponse
    {
        return $this->repository->get($key);
    }

    public function put(string $key, StoredResponse $response, int $ttl): void
    {
        $this->lastPutTtl = $ttl;

        $this->repository->put($key, $response, $ttl);
    }

    public function forget(string $key): void
    {
        $this->repository->forget($key);
    }

    public function lock(string $key, int $seconds): Lock
    {
        $this->lastLockSeconds = $seconds;

        return $this->repository->lock($key, $seconds);
    }
}
