<?php

namespace Webrek\Idempotency\Tests\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * A lock that never grants immediate ownership (`get()` always fails) but can
 * be scripted to have `block()` either succeed or time out, so
 * `wait_for_completion` can be exercised without a real second process.
 */
final class ScriptedLock implements Lock
{
    public bool $blockCalled = false;

    /**
     * The raw value passed to `block()`, kept untouched (no cast) so a test
     * can assert the caller passed a genuine int rather than e.g. a numeric
     * string.
     */
    public mixed $blockSeconds = null;

    public function __construct(
        protected bool $blockSucceeds,
    ) {}

    public function get($callback = null)
    {
        return false;
    }

    public function block($seconds, $callback = null)
    {
        $this->blockCalled = true;
        $this->blockSeconds = $seconds;

        if (! $this->blockSucceeds) {
            throw new LockTimeoutException;
        }

        return true;
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
