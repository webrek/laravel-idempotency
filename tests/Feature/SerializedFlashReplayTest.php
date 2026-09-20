<?php

namespace Webrek\Idempotency\Tests\Feature;

/**
 * Runs every flash-replay scenario against a cache store that serialises its
 * values and refuses to unserialise objects — Laravel 13's default
 * (`cache.serializable_classes => false`). This is what a real Redis, file or
 * database store does in production, unlike the plain array store.
 */
class SerializedFlashReplayTest extends FlashReplayTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.stores.array.serialize', true);
        $app['config']->set('cache.serializable_classes', false);
    }
}
