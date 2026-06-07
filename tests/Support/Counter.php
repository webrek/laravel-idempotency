<?php

namespace Webrek\Idempotency\Tests\Support;

/**
 * A process-wide counter the test routes increment on every execution, so a
 * replayed (rather than re-executed) request is observable in assertions.
 */
final class Counter
{
    public static int $count = 0;

    public static function next(): int
    {
        return ++self::$count;
    }

    public static function reset(): void
    {
        self::$count = 0;
    }
}
