# Laravel Idempotency

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webrek/laravel-idempotency.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-idempotency)
[![Total Downloads](https://img.shields.io/packagist/dt/webrek/laravel-idempotency.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-idempotency)
[![Tests](https://img.shields.io/github/actions/workflow/status/webrek/laravel-idempotency/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/webrek/laravel-idempotency/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/webrek/laravel-idempotency.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/webrek/laravel-idempotency.svg?style=flat-square)](LICENSE)

Safe request retries for Laravel APIs. A client sends an `Idempotency-Key`
header with a write request; if that exact request arrives again — a retry
after a timeout, a double-tapped button, a webhook redelivery — the original
response is replayed instead of the action running twice.

## Quickstart

```bash
composer require webrek/laravel-idempotency
```

Attach the middleware to the routes that create or mutate state:

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
`Idempotency-Replayed: true` header so the client can tell a replay from a fresh
result. No key, no interception — existing callers keep working.

## The problem

`POST` is not safe to retry. When a client fires a write request and the
connection drops before the response comes back, it has no way to know whether
the server processed it. Both choices are bad: retry and you risk a duplicate
charge, order, or signup; don't retry and you risk silently losing the write.

Idempotency keys resolve the ambiguity. The client generates one key per logical
operation and reuses it on every retry of that operation. The server promises
that all requests sharing a key produce **one** execution and the **same**
response. This is how Stripe, PayPal, Adyen and most serious payment APIs make
retries safe — and it is exactly what this package adds to your Laravel routes.

## How it works

The middleware sits in front of your guarded routes and does four things:

1. **Fingerprints the request.** A SHA-256 of the method, path and raw body is
   stored alongside the response. If the same key arrives later with a different
   payload, that is a client bug, and the request is rejected with `422` rather
   than silently returning the wrong cached response.
2. **Serialises concurrent duplicates with an atomic lock.** Two requests
   carrying the same key at the same time cannot both execute. The first takes
   the lock and runs; the second gets `409 Conflict` with a `Retry-After`
   header. The lock auto-expires, so a crashed worker never wedges a key.
3. **Replays the stored response.** Status code, body and a configurable set of
   headers are returned on subsequent hits — without touching your controller,
   queue jobs, or database.
4. **Leaves failures retryable.** Server errors (`5xx`) are never stored, so a
   client can safely retry after a transient failure. Successes and
   deterministic client errors are replayed.

Everything lives in Laravel's cache, using the same atomic locks `Cache::lock()`
exposes. There are no migrations and no new tables.

## Behaviour at a glance

| Scenario | Result |
| --- | --- |
| First request with a key | Executes, stores the response, `Idempotency-Replayed: false` |
| Same key, same payload, after completion | Replays the stored response, `Idempotency-Replayed: true` |
| Same key, same payload, still in flight | `409 Conflict` + `Retry-After` |
| Same key, **different** payload | `422 Unprocessable Entity` |
| No key (and `require_key` is false) | Passes through untouched |
| `GET` / `HEAD` request | Ignored — already safe to repeat |
| Response is `5xx` | Not stored — the next attempt re-executes |

## Requirements

| Component | Version |
| --------- | ------- |
| PHP | 8.2+ |
| Laravel | 12.x |
| Cache store | Any store that supports atomic locks (redis, memcached, dynamodb, database, file, array) |

## Configuration

The defaults are production-ready. Publish the config only if you need to change
them:

```bash
php artisan vendor:publish --tag=idempotency-config
```

```php
return [
    // Header clients send to identify a retryable operation.
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),

    // Reject keyless requests on guarded routes with a 400 when true.
    'require_key' => false,

    // HTTP methods the middleware guards. GET/HEAD are already safe.
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    // Cache store for stored responses and locks (null = default store).
    'store' => env('IDEMPOTENCY_STORE'),

    'prefix' => 'idempotency:',

    // How long a response stays replayable, in seconds.
    'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),

    // Max time one request may hold its key's lock, in seconds.
    'lock_timeout' => 10,

    'max_key_length' => 255,

    // Namespace keys by authenticated user so callers can't collide.
    'scope_by_user' => true,

    // Null replays everything < 500; or list explicit codes, e.g. [200, 201, 422].
    'replay_status_codes' => null,

    // Headers copied onto the replayed response.
    'persist_headers' => ['Content-Type'],

    // Marker added to every guarded response: "true" | "false".
    'replay_header' => 'Idempotency-Replayed',
];
```

### Requiring a key on specific routes

Leave `require_key` off globally and opt individual routes in by flipping the
config at the boundary, or set it to `true` if every guarded route must carry a
key. With it on, a guarded request without the header is rejected with `400`
before any work is done.

### Choosing a cache store

Replays are only as durable as the store behind them. `array` is for tests; in
production point `IDEMPOTENCY_STORE` at `redis` (or any shared, persistent store
with atomic locks) so replays survive across web workers and deploys. A
per-process store like `array` cannot coordinate locks across machines.

## Client guidance

- **One key per logical operation, reused on retry.** Generate a UUID before the
  first attempt and send the *same* value on every retry of that attempt. A new
  key per retry defeats the purpose.
- **Handle `409` by backing off and retrying** — it means an earlier attempt is
  still running. Respect the `Retry-After` header.
- **Treat `422` as a bug on your side** — it means you reused a key for a
  genuinely different request.

## Comparison with hand-rolled approaches

| Approach | Concurrency-safe | Payload mismatch detection | Replays full response | Migrations |
| --- | --- | --- | --- | --- |
| `firstOrCreate` on a `request_id` column | No (race between check and insert) | No | No | Yes |
| Unique DB constraint + catch duplicate | Partially (relies on the write reaching the constrained table) | No | No | Yes |
| This package | Yes (atomic lock) | Yes (request fingerprint) | Yes | No |

A unique constraint stops a duplicate *row*, but it does not stop the duplicate
side effects that ran before the insert (the email already sent, the third-party
charge already made), and it gives the client an error instead of the original
success. Idempotency at the HTTP boundary stops the second execution entirely
and hands back the first response.

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
