# Laravel Idempotency

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webrek/laravel-idempotency.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-idempotency)
[![Total Downloads](https://img.shields.io/packagist/dt/webrek/laravel-idempotency.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-idempotency)
[![Tests](https://img.shields.io/github/actions/workflow/status/webrek/laravel-idempotency/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/webrek/laravel-idempotency/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/webrek/laravel-idempotency.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/webrek/laravel-idempotency.svg?style=flat-square)](LICENSE)

Safe request retries for Laravel APIs. A client sends an `Idempotency-Key`
header with a write request; if that same request arrives again—a retry after a
timeout, a double-clicked button, a webhook redelivery—the original response is
replayed instead of executing the action twice.

## Quickstart

```bash
composer require webrek/laravel-idempotency
```

Attach the middleware to routes that create or modify state:

```php
Route::post('/orders', [OrderController::class, 'store'])
    ->middleware('idempotency');
```

Clients opt in per request by sending a unique key:

```http
POST /orders HTTP/1.1
Idempotency-Key: 0f8fad5b-d9cb-469f-a165-70867728950e
Content-Type: application/json

{"sku": "ABC-123", "qty": 2}
```

The first call runs the controller and stores the response. Any repeat of that
call within the retention window returns the stored response verbatim, with an
`Idempotency-Replayed: true` header so the client can tell a replay apart from a
fresh result. Without a key there is no interception: existing callers keep
working.

## The problem

`POST` is not safe to retry. When a client fires off a write request and the
connection drops before the response comes back, it has no way of knowing
whether the server processed it. Both options are bad: if you retry, you risk a
duplicate charge, order, or record; if you don't retry, you risk silently
losing the write.

Idempotency keys resolve the ambiguity. The client generates one key per logical
operation and reuses it on every retry of that operation. The server promises
that all requests sharing a key produce **one** execution and the **same**
response. This is how Stripe, PayPal, Adyen, and most serious payment APIs make
retries safe, and it is exactly what this package adds to your Laravel routes.

## How it works

The middleware sits in front of your protected routes and does four things:

1. **Fingerprints the request.** A SHA-256 of the method, path, and raw body is
   stored alongside the response. If the same key later arrives with a different
   payload, that is a client error, and the request is rejected with `422`
   instead of silently returning the wrong cached response.
2. **Serializes concurrent duplicates with an atomic lock.** Two requests
   carrying the same key at the same time cannot both run. The first takes the
   lock and executes; the second gets `409 Conflict` with a `Retry-After`
   header. The lock expires automatically, so a crashed worker never leaves a
   key stuck.
3. **Replays the stored response.** The status code, body, and a configurable
   set of headers are returned on subsequent hits, without touching your
   controller, your queued jobs, or your database.
4. **Leaves failures retryable.** Server errors (`5xx`) are never stored, so a
   client can safely retry after a transient failure. Successes and
   deterministic client errors are replayed.

Everything lives in Laravel's cache, using the same atomic locks that
`Cache::lock()` exposes. There are no migrations and no new tables.

## Behavior at a glance

| Scenario | Result |
| --- | --- |
| First request with a key | Executes, stores the response, `Idempotency-Replayed: false` |
| Same key, same payload, after completion | Replays the stored response, `Idempotency-Replayed: true` |
| Same key, same payload, still in progress | `409 Conflict` + `Retry-After` |
| Same key, **different** payload | `422 Unprocessable Entity` |
| No key (and `require_key` is false) | Passes through untouched |
| `GET` / `HEAD` request | Ignored: already safe to repeat |
| Response is `5xx` | Not stored: the next attempt re-runs it |

## Requirements

| Component | Version |
| --------- | ------- |
| PHP | 8.2+ |
| Laravel | 12.x / 13.x |
| Cache store | Any store that supports atomic locks (redis, memcached, dynamodb, database, file, array) |

## Configuration

The defaults are production-ready. Publish the configuration only if you need to
change them:

```bash
php artisan vendor:publish --tag=idempotency-config
```

```php
return [
    // Header clients send to identify a retryable operation.
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),

    // Rejects keyless requests on protected routes with 400 when true.
    'require_key' => false,

    // HTTP methods the middleware protects. GET/HEAD are already safe.
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    // Cache store for responses and locks (null = default store).
    'store' => env('IDEMPOTENCY_STORE'),

    'prefix' => 'idempotency:',

    // How long a response stays replayable, in seconds.
    'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),

    // Maximum time a request holds the lock for its key, in seconds.
    'lock_timeout' => 10,

    'max_key_length' => 255,

    // Scopes keys per authenticated user so callers don't collide.
    'scope_by_user' => true,

    // Null replays everything < 500; or list explicit codes, e.g. [200, 201, 422].
    'replay_status_codes' => null,

    // Headers copied to the replayed response.
    'persist_headers' => ['Content-Type'],

    // Flag added to every protected response: "true" | "false".
    'replay_header' => 'Idempotency-Replayed',
];
```

### Per-route retention

Override the configured TTL (in seconds) for specific routes by passing it as a
middleware parameter:

```php
Route::post('/payments', ...)->middleware('idempotency:3600');   // 1 hour
Route::post('/imports', ...)->middleware('idempotency:86400');   // 1 day
```

### Replay event

An `Idempotency\Events\IdempotentReplay` event is dispatched every time a stored
response is replayed, so you can measure how many retries you are absorbing:

```php
use Webrek\Idempotency\Events\IdempotentReplay;

Event::listen(IdempotentReplay::class, function (IdempotentReplay $event) {
    Metrics::increment('idempotency.replays', tags: ['key' => $event->key]);
});
```

### Requiring a key on specific routes

Leave `require_key` disabled globally and opt in individual routes by changing
the configuration at the entry point, or set it to `true` if every protected
route must carry a key. With this enabled, a protected request without the
header is rejected with `400` before doing any work.

### Choosing a cache store

Replays are only as durable as the store backing them. `array` is for testing;
in production point `IDEMPOTENCY_STORE` at `redis` (or any shared, persistent
store with atomic locks) so replays survive across web workers and deployments.
A per-process store like `array` cannot coordinate locks across machines.

## Client guidance

- **One key per logical operation, reused on retry.** Generate a UUID before the
  first attempt and send the *same* value on every retry of that attempt. A new
  key per retry defeats the purpose.
- **Handle `409` by backing off and retrying**: it means an earlier attempt is
  still running. Respect the `Retry-After` header.
- **Treat `422` as a bug on your side**: it means you reused a key for a
  genuinely different request.

## Comparison with homegrown approaches

| Approach | Concurrency-safe | Detects different payload | Replays the full response | Migrations |
| --- | --- | --- | --- | --- |
| `firstOrCreate` on a `request_id` column | No (race between the check and the insert) | No | No | Yes |
| Unique DB constraint + catch duplicate | Partially (depends on the write reaching the constrained table) | No | No | Yes |
| This package | Yes (atomic lock) | Yes (request fingerprint) | Yes | No |

A unique constraint stops a duplicate *row*, but it does not stop the duplicate
side effects that ran before the insert (the email already sent, the third-party
charge already made), and it hands the client an error instead of the original
success. Idempotency at the HTTP boundary stops the second execution entirely
and returns the first response.

## Testing

```bash
composer install
composer test
```

The suite runs on the `array` cache store, so no external services are needed.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Please review the [security policy](SECURITY.md) before reporting a
vulnerability.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
