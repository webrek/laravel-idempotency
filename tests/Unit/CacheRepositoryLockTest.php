<?php

namespace Webrek\Idempotency\Tests\Unit;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Store;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webrek\Idempotency\Repositories\CacheRepository;

class CacheRepositoryLockTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_lock_throws_when_the_store_does_not_support_atomic_locks(): void
    {
        // A plain Store (not a LockProvider) cannot provide atomic locks.
        $store = Mockery::mock(Store::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('store')->andReturn(new Repository($store));

        $repository = new CacheRepository($factory, 'unsupported', 'idempotency:');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported');

        $repository->lock('k', 10);
    }
}
