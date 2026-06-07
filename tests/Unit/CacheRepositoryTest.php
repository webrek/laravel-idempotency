<?php

namespace Webrek\Idempotency\Tests\Unit;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Webrek\Idempotency\Repositories\CacheRepository;
use Webrek\Idempotency\StoredResponse;
use Webrek\Idempotency\Tests\TestCase;

class CacheRepositoryTest extends TestCase
{
    private function repository(): CacheRepository
    {
        return new CacheRepository(
            $this->app->make(CacheFactory::class),
            'array',
            'idempotency:',
        );
    }

    public function test_it_round_trips_a_stored_response(): void
    {
        $repository = $this->repository();

        $repository->put('k', new StoredResponse(201, '{"ok":true}', ['Content-Type' => 'application/json'], 'fp'), 60);

        $stored = $repository->get('k');

        $this->assertNotNull($stored);
        $this->assertSame(201, $stored->status);
        $this->assertSame('{"ok":true}', $stored->body);
        $this->assertSame(['Content-Type' => 'application/json'], $stored->headers);
        $this->assertSame('fp', $stored->fingerprint);
    }

    public function test_it_returns_null_for_a_missing_key(): void
    {
        $this->assertNull($this->repository()->get('nope'));
    }

    public function test_forget_removes_a_stored_response(): void
    {
        $repository = $this->repository();

        $repository->put('k', new StoredResponse(200, '', [], 'fp'), 60);
        $repository->forget('k');

        $this->assertNull($repository->get('k'));
    }

    public function test_a_held_lock_blocks_a_second_acquirer(): void
    {
        $repository = $this->repository();

        $first = $repository->lock('k', 10);
        $second = $repository->lock('k', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());

        $first->release();
    }
}
