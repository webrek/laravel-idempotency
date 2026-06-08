<?php

namespace Webrek\Idempotency\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webrek\Idempotency\Contracts\IdempotencyRepository;
use Webrek\Idempotency\Events\IdempotentReplay;
use Webrek\Idempotency\Exceptions\ConcurrentRequestException;
use Webrek\Idempotency\Exceptions\IdempotencyConflictException;
use Webrek\Idempotency\Exceptions\InvalidIdempotencyKeyException;
use Webrek\Idempotency\Exceptions\MissingIdempotencyKeyException;
use Webrek\Idempotency\StoredResponse;

/**
 * Replays the original response for a repeated Idempotency-Key instead of
 * executing the request twice, and serialises concurrent requests that share
 * a key so the underlying action runs exactly once.
 */
class EnsureIdempotency
{
    public function __construct(
        protected IdempotencyRepository $repository,
        protected Config $config,
    ) {}

    public function handle(Request $request, Closure $next, ?string $ttl = null): Response
    {
        if (! $this->guards($request)) {
            return $next($request);
        }

        $key = $this->resolveKey($request);

        if ($key === null) {
            if ($this->config('require_key', false)) {
                throw new MissingIdempotencyKeyException($this->config('header', 'Idempotency-Key'));
            }

            return $next($request);
        }

        $cacheKey = $this->cacheKey($request, $key);
        $fingerprint = $this->fingerprint($request);
        $ttl = $ttl !== null ? (int) $ttl : (int) $this->config('ttl', 86400);

        if ($stored = $this->repository->get($cacheKey)) {
            return $this->replay($stored, $fingerprint, $request, $key);
        }

        $lock = $this->repository->lock($cacheKey, (int) $this->config('lock_timeout', 10));

        if (! $lock->get()) {
            throw new ConcurrentRequestException;
        }

        try {
            // Another request may have completed between our first read and
            // acquiring the lock; re-check before doing the work again.
            if ($stored = $this->repository->get($cacheKey)) {
                return $this->replay($stored, $fingerprint, $request, $key);
            }

            $response = $next($request);

            if ($this->isCacheable($response)) {
                $this->repository->put(
                    $cacheKey,
                    StoredResponse::capture($response, $fingerprint, $this->config('persist_headers', [])),
                    $ttl,
                );
            }

            return $this->mark($response, replayed: false);
        } finally {
            $lock->release();
        }
    }

    protected function guards(Request $request): bool
    {
        $methods = array_map('strtoupper', (array) $this->config('methods', []));

        return in_array($request->getMethod(), $methods, true);
    }

    protected function resolveKey(Request $request): ?string
    {
        $value = $request->headers->get($this->config('header', 'Idempotency-Key'));
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        $max = (int) $this->config('max_key_length', 255);

        if (mb_strlen($value) > $max) {
            throw new InvalidIdempotencyKeyException("The idempotency key may not be longer than {$max} characters.");
        }

        return $value;
    }

    protected function cacheKey(Request $request, string $key): string
    {
        $parts = [$key];

        if ($this->config('scope_by_user', true) && ($user = $request->user()) !== null) {
            $parts[] = 'u:' . $user->getAuthIdentifier();
        }

        return hash('sha256', implode('|', $parts));
    }

    protected function fingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->getMethod(),
            $request->getPathInfo(),
            $request->getContent(),
        ]));
    }

    protected function replay(StoredResponse $stored, string $fingerprint, Request $request, string $key): Response
    {
        if (! hash_equals($stored->fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException;
        }

        event(new IdempotentReplay($key, $request, $stored));

        return $this->mark($stored->toResponse(), replayed: true);
    }

    protected function isCacheable(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        $codes = $this->config('replay_status_codes');

        if (is_array($codes) && $codes !== []) {
            return in_array($response->getStatusCode(), $codes, true);
        }

        return $response->getStatusCode() < 500;
    }

    protected function mark(Response $response, bool $replayed): Response
    {
        $response->headers->set($this->config('replay_header', 'Idempotency-Replayed'), $replayed ? 'true' : 'false');

        return $response;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get("idempotency.{$key}", $default);
    }
}
