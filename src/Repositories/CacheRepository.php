<?php

namespace Webrek\Idempotency\Repositories;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\StoredResponse;

class CacheRepository implements IdempotencyRepository
{
    public function __construct(
        protected Factory $cache,
        protected ?string $store,
        protected string $prefix,
    ) {}

    public function get(string $key): ?StoredResponse
    {
        $raw = $this->store()->get($this->prefix . $key);

        return is_array($raw) ? StoredResponse::fromArray($raw) : null;
    }

    public function put(string $key, StoredResponse $response, int $ttl): void
    {
        $this->store()->put($this->prefix . $key, $response->toArray(), $ttl);
    }

    public function forget(string $key): void
    {
        $this->store()->forget($this->prefix . $key);
    }

    public function lock(string $key, int $seconds): Lock
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException(sprintf(
                'The [%s] cache store does not support the atomic locks idempotency requires.',
                $this->store ?? 'default',
            ));
        }

        return $store->lock($this->prefix . $key . ':lock', $seconds);
    }

    protected function store(): Cache
    {
        return $this->cache->store($this->store);
    }
}
