<?php

namespace Webrek\Idempotency\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        protected Dispatcher $events,
    ) {}

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        ['ttl' => $routeTtl, 'required' => $required] = $this->parseOptions($options);

        if (! $this->guards($request)) {
            return $next($request);
        }

        $key = $this->resolveKey($request);

        if ($key === null) {
            if ($required || $this->config('require_key', false)) {
                throw new MissingIdempotencyKeyException($this->config('header', 'Idempotency-Key'));
            }

            return $next($request);
        }

        $ttl = $this->assertPositiveSetting('idempotency.ttl', $routeTtl ?? (int) $this->config('ttl', 86400));
        $lockTimeout = $this->assertPositiveSetting('idempotency.lock_timeout', (int) $this->config('lock_timeout', 10));

        $cacheKey = $this->cacheKey($request, $key);
        $fingerprint = $this->fingerprint($request);

        if ($stored = $this->repository->get($cacheKey)) {
            return $this->replay($stored, $fingerprint, $request, $key);
        }

        $lock = $this->repository->lock($cacheKey, $lockTimeout);

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
                    StoredResponse::capture($response, $fingerprint, $this->persistedHeaders()),
                    $ttl,
                );
            }

            return $this->mark($response, replayed: false);
        } finally {
            $lock->release();
        }
    }

    /**
     * Parse the loosely-typed middleware options into a TTL override and the
     * "required" flag. Each option is either a positive integer string (a
     * per-route TTL in seconds) or the literal "required"; empty strings are
     * ignored so `idempotency:,required` also works.
     *
     * @param  array<int, string>  $options
     * @return array{ttl: int|null, required: bool}
     */
    protected function parseOptions(array $options): array
    {
        $ttl = null;
        $required = false;

        foreach ($options as $option) {
            if ($option === '') {
                continue;
            }

            if ($option === 'required') {
                $required = true;

                continue;
            }

            if (ctype_digit($option) && (int) $option >= 1) {
                $ttl = (int) $option;

                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Invalid "idempotency" middleware option "%s"; expected a positive integer TTL or "required".',
                $option,
            ));
        }

        return ['ttl' => $ttl, 'required' => $required];
    }

    protected function assertPositiveSetting(string $name, int $value): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$name} must be a positive number of seconds, got {$value}.");
        }

        return $value;
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
        $parts = ['k:' . hash('sha256', $key)];

        if ($this->config('scope_by_user', true) && ($user = $request->user()) !== null) {
            $parts[] = 'u:' . $user::class . ':' . $user->getAuthIdentifier();
        }

        return hash('sha256', implode('|', $parts));
    }

    protected function fingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->getMethod(),
            $request->getRequestUri(),
            $this->fingerprintBody($request),
        ]));
    }

    protected function fingerprintBody(Request $request): string
    {
        $raw = $request->getContent();

        if ($raw !== '') {
            return $raw;
        }

        $body = json_encode($this->normalise($request->request->all()));
        $body = $body === false ? '' : $body;

        foreach ($this->fingerprintFiles($request) as $path => $file) {
            $body .= sprintf(
                '|f:%s:%s:%d:%s',
                $path,
                $file->getClientOriginalName(),
                (int) $file->getSize(),
                $this->fileHash($file),
            );
        }

        return $body;
    }

    /**
     * Flatten every uploaded file into a dot-path-keyed, deterministically
     * ordered list so the fingerprint does not depend on submission order.
     *
     * @return array<string, UploadedFile>
     */
    protected function fingerprintFiles(Request $request): array
    {
        $files = $this->flattenFiles($request->allFiles());

        ksort($files);

        return $files;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<string, UploadedFile>
     */
    protected function flattenFiles(array $files, string $prefix = ''): array
    {
        $flat = [];

        foreach ($files as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if ($value instanceof UploadedFile) {
                $flat[$path] = $value;

                continue;
            }

            if (is_array($value)) {
                $flat += $this->flattenFiles($value, $path);
            }
        }

        return $flat;
    }

    protected function fileHash(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return 'unreadable';
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? 'unreadable' : $hash;
    }

    /**
     * Recursively ksort an array so its serialisation does not depend on the
     * order fields were submitted in.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    protected function normalise(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->normalise($value);
            }
        }

        ksort($input);

        return $input;
    }

    protected function replay(StoredResponse $stored, string $fingerprint, Request $request, string $key): Response
    {
        if (! hash_equals($stored->fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException;
        }

        $this->events->dispatch(new IdempotentReplay($key, $request, $stored));

        return $this->mark($stored->toResponse(), replayed: true);
    }

    protected function isCacheable(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        $maxBodySize = (int) $this->config('max_body_size', 0);

        if ($maxBodySize > 0 && strlen((string) $response->getContent()) > $maxBodySize) {
            return false;
        }

        $codes = $this->config('replay_status_codes');

        if (is_array($codes) && $codes !== []) {
            return in_array($response->getStatusCode(), $codes, true);
        }

        $neverReplay = (array) $this->config('never_replay_status_codes', [408, 425, 429]);

        return $response->getStatusCode() < 500 && ! in_array($response->getStatusCode(), $neverReplay, true);
    }

    /**
     * @return list<string>
     */
    protected function persistedHeaders(): array
    {
        /** @var list<string> $configured */
        $configured = (array) $this->config('persist_headers', []);

        return array_values(array_unique(array_merge($configured, ['Location'])));
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
