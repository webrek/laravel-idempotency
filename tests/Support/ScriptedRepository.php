<?php

namespace Webrek\Idempotency\Tests\Support;

use Illuminate\Contracts\Cache\Lock;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\StoredResponse;

/**
 * Wraps the real repository so `wait_for_completion` tests can control what
 * the middleware sees without a real concurrent process: `lock()` always
 * hands back the given `ScriptedLock`, the first `get()` call (before the
 * lock attempt) always misses, and every call after that returns whatever
 * the test configured — simulating the original request completing while
 * this one was waiting.
 */
final class ScriptedRepository implements IdempotencyRepository
{
    public int $getCalls = 0;

    public function __construct(
        protected IdempotencyRepository $repository,
        protected ScriptedLock $scriptedLock,
        protected ?StoredResponse $afterWait = null,
    ) {}

    public function get(string $key): ?StoredResponse
    {
        $this->getCalls++;

        return $this->getCalls === 1 ? null : $this->afterWait;
    }

    public function put(string $key, StoredResponse $response, int $ttl): void
    {
        $this->repository->put($key, $response, $ttl);
    }

    public function forget(string $key): void
    {
        $this->repository->forget($key);
    }

    public function lock(string $key, int $seconds): Lock
    {
        return $this->scriptedLock;
    }
}
