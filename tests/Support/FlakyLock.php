<?php

namespace Webrek\Idempotency\Tests\Support;

use Illuminate\Contracts\Cache\Lock;
use RuntimeException;

final class FlakyLock implements Lock
{
    public function __construct(
        protected Lock $lock,
        protected FlakyRepository $repository,
    ) {}

    public function get($callback = null)
    {
        return $this->lock->get($callback);
    }

    public function block($seconds, $callback = null)
    {
        return $this->lock->block($seconds, $callback);
    }

    public function release()
    {
        if ($this->repository->failRelease) {
            throw new RuntimeException('cache unreachable during release');
        }

        return $this->lock->release();
    }

    public function owner()
    {
        return $this->lock->owner();
    }

    public function forceRelease()
    {
        $this->lock->forceRelease();
    }
}
