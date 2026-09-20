<?php

namespace Webrek\Idempotency\Tests\Support;

use Illuminate\Contracts\Cache\Lock;
use LogicException;

/**
 * A lock whose `get()` answers follow a script (the last answer repeats), so
 * `wait_for_completion` can be exercised without a real second process.
 * `block()` is never expected: the middleware polls the store instead.
 */
final class ScriptedLock implements Lock
{
    public int $getCalls = 0;

    public bool $blockCalled = false;

    /**
     * @param  non-empty-list<bool>  $getResults
     */
    public function __construct(
        protected array $getResults = [false],
    ) {}

    public function get($callback = null)
    {
        $this->getCalls++;

        return $this->getResults[min($this->getCalls, count($this->getResults)) - 1];
    }

    public function block($seconds, $callback = null)
    {
        $this->blockCalled = true;

        throw new LogicException('EnsureIdempotency must not block on the lock.');
    }

    public function release()
    {
        return true;
    }

    public function owner()
    {
        return '';
    }

    public function forceRelease()
    {
        //
    }
}
