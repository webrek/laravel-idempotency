<?php

namespace Webrek\Idempotency\Tests\Support;

use Illuminate\Contracts\Cache\Lock;
use RuntimeException;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\StoredResponse;

/**
 * Wraps the real repository and, on demand, makes `put` or the returned
 * lock's `release` throw, simulating a cache that became unreachable after
 * the request already ran.
 */
final class FlakyRepository implements IdempotencyRepository
{
    public bool $failPut = false;

    public bool $failRelease = false;

    public function __construct(
        protected IdempotencyRepository $repository,
    ) {}

    public function get(string $key): ?StoredResponse
    {
        return $this->repository->get($key);
    }

    public function put(string $key, StoredResponse $response, int $ttl): void
    {
        if ($this->failPut) {
            throw new RuntimeException('cache unreachable during put');
        }

        $this->repository->put($key, $response, $ttl);
    }

    public function forget(string $key): void
    {
        $this->repository->forget($key);
    }

    public function lock(string $key, int $seconds): Lock
    {
        return new FlakyLock($this->repository->lock($key, $seconds), $this);
    }
}
