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

The middleware sits in front of your protected routes and does five things:

1. **Fingerprints the request.** A SHA-256 of the method, the full URI
   (including the query string), and the raw body is stored alongside the
   response, so JSON and form-encoded bodies are compared byte for byte. For
   `multipart/form-data` requests, where PHP does not expose the raw body, the
   fingerprint instead covers the parsed fields (order-independent) and, for
   each uploaded file, its field path, original name, size, and content hash.
   If the same key later arrives with a different payload, that is a client
   error, and the request is rejected with `422` instead of silently returning
   the wrong cached response.
2. **Serializes concurrent duplicates with an atomic lock.** Two requests
   carrying the same key at the same time cannot both run. The first takes the
   lock and executes; the second gets `409 Conflict` with a `Retry-After`
   header. The lock expires automatically, so a crashed worker never leaves a
   key stuck.
3. **Replays the stored response.** The status code, body, and a configurable
   set of headers are returned on subsequent hits, without touching your
   controller, your queued jobs, or your database.
4. **Leaves failures retryable.** Server errors (`5xx`) are never stored, so a
   client can safely retry after a transient failure. Transient client errors
   (`408`, `425`, `429` by default) are treated the same way. Successes and
   other deterministic client errors are replayed.
5. **Never turns a completed request into an error.** If the cache becomes
   unreachable *after* your controller ran, the fresh response is still
   returned: the failure is reported to your exception handler and an
   `IdempotencyStorageFailed` event fires. Nothing was stored, so a retry of
   that key executes again (at-least-once), which is the correct fallback —
   the alternative would be a `500` for work that already succeeded.

Everything lives in Laravel's cache, using the same atomic locks that
`Cache::lock()` exposes. There are no migrations and no new tables.

## Behavior at a glance

| Scenario | Result |
| --- | --- |
| First request with a key | Executes, stores the response, `Idempotency-Replayed: false` |
| Same key, same payload, after completion | Replays the stored response, `Idempotency-Replayed: true` |
| Same key, same payload, still in progress | `409 Conflict` + `Retry-After` |
| Same key, still in progress, `wait_for_completion` > 0 | Waits up to that many seconds, replaying as soon as the original finishes, otherwise `409` |
| Same key, **different** payload | `422 Unprocessable Entity` |
| No key (and `require_key` is false) | Passes through untouched |
| `GET` / `HEAD` request | Ignored: already safe to repeat |
| Response is `5xx` | Not stored: the next attempt re-runs it |
| Response is `408`, `425`, or `429` | Not stored by default (`never_replay_status_codes`): the next attempt re-runs it |
| Response body exceeds `max_body_size` | Not stored: the next attempt re-runs it |
| Cache unreachable after the controller ran | Fresh response returned, failure reported, `IdempotencyStorageFailed` fired; the next attempt re-runs it |
| Browser form request rejected (missing/invalid/conflicting key, in-progress) | Redirects back with the input re-flashed and a translated message, instead of throwing (`redirect_back`, session requests only) |

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

    // Maximum time a request holds the lock for its key, in seconds. A request
    // that runs longer than this may be executed twice by a concurrent retry.
    'lock_timeout' => (int) env('IDEMPOTENCY_LOCK_TIMEOUT', 10),

    'max_key_length' => 255,

    // Scopes keys per authenticated user so callers don't collide.
    'scope_by_user' => true,

    // Null replays everything < 500 and not in never_replay_status_codes; or
    // list explicit codes, e.g. [200, 201, 422], to replay only those.
    'replay_status_codes' => null,

    // Transient client errors that are never stored, even though they are
    // below 500. Only consulted when replay_status_codes is null.
    'never_replay_status_codes' => [408, 425, 429],

    // Responses larger than this are never stored. 0 disables the limit.
    'max_body_size' => 1024 * 1024,

    // Headers copied to the replayed response. Location is always persisted
    // even if removed from this list.
    'persist_headers' => ['Content-Type', 'Location'],

    // Flag added to every protected response: "true" | "false".
    'replay_header' => 'Idempotency-Replayed',

    // Form field read when the header is absent or blank. Rendered by the
    // `@idempotencyKey` Blade directive. Null disables the fallback.
    'input' => '_idempotency_key',

    // Instead of an immediate 409, wait up to this many seconds for the
    // in-progress request to finish and replay its response. 0 disables it.
    'wait_for_completion' => 0,

    // Re-flashes the session data (errors, old input, status) captured
    // alongside a stored response when it is replayed.
    'replay_flash' => true,

    // Redirects back with a translated error instead of throwing, for a
    // request that carries a session and does not expect JSON.
    'redirect_back' => true,

    // Key under which the translated rejection message is flashed to the
    // errors bag, e.g. $errors->first('idempotency').
    'error_key' => 'idempotency',
];
```

### Per-route retention

Override the configured TTL (in seconds) for specific routes by passing it as a
middleware parameter:

```php
Route::post('/payments', ...)->middleware('idempotency:3600');   // 1 hour
Route::post('/imports', ...)->middleware('idempotency:86400');   // 1 day
```

### Web forms (Blade)

Classic HTML forms cannot send custom headers, their responses are redirects
carrying session flash data instead of a JSON body, and a human — not a
retrying HTTP client — is on the other side. This package supports that case
too.

Add the hidden field to any form on a route guarded by the `idempotency`
middleware:

```blade
<form method="POST" action="/orders">
    @csrf
    @idempotencyKey
    {{-- ... --}}
</form>
```

`@idempotencyKey` renders a hidden input carrying a fresh UUID, using the
field name configured under `input` (default `_idempotency_key`):

```html
<input type="hidden" name="_idempotency_key" value="9b1f2b1e-...-...">
```

The middleware reads this field only when the `Idempotency-Key` header is
absent or blank — a header, when present, always wins — so the same route
keeps working for API clients that send the header directly. Set `input` to
`null` to disable the field fallback and require the header everywhere.

With this in place:

- **Double-click**: a second click before the page navigates away resubmits
  the exact same in-memory form, hidden field included, so it replays the
  first response instead of creating a duplicate.
- **F5 / reload on the response page, or back-then-forward**: a browser's
  native "resend form data" resubmits the exact same `POST` body, hidden
  field included, so this replays too.
- **Validation error**: this is where flash replay (below) matters. The
  redirect back to the form is a fresh `GET`, so `@idempotencyKey` mints a
  new value for that page load — correcting the input and resubmitting is a
  genuinely different submission and executes normally, it does not replay a
  stale error. What *does* replay is resubmitting the same invalid data a
  second time (double-click or resend, as above): previously that lost its
  error message and old input on the replay; now it shows them correctly.
- **Back button**: only replays if the browser restores the exact prior
  `POST` (native resubmission) rather than a fresh `GET` re-render of the
  form.

#### Flash replay

A replayed redirect is, from the session's point of view, "the next
request": it ages the original flash data without re-flashing it, so a
naively replayed validation redirect would show no errors and no old input,
and a replayed success redirect would lose its status message. When
`replay_flash` is `true` (the default), the flash data set while the request
was first executed — errors, old input, status, or anything else flashed via
`with()` — is captured alongside the stored response and re-flashed before
the replay is returned, so the second submission's redirect looks exactly
like the first.

Laravel's cache stores refuse to unserialise objects unless they are
allowlisted (`cache.serializable_classes`, `false` by default), so the flash is
stored as plain data: validation error bags (`ViewErrorBag`, `MessageBag`) are
flattened on the way in and rebuilt on replay, scalars and plain arrays pass
through untouched, and any other object flashed via `with()` is dropped rather
than risk an incomplete class on replay.

That flash data lands in the same cache entry as the response, with the same
sensitivity as the session itself. Laravel already keeps `password`,
`password_confirmation`, and `current_password` out of the flashed old input,
so they are never captured either.

#### Human-friendly rejections

By default (`redirect_back` is `true`), the four client-facing rejections —
missing key, invalid key, conflict, in-progress — redirect back instead of
throwing, whenever the request carries a session and does not expect JSON
(API requests, and any request sending `Accept: application/json`, keep
getting the plain `400`/`409`/`422` response). The input is re-flashed
(except passwords and the `input` field above) and a translated message is
flashed to the `errors` bag under `error_key` (default `idempotency`):

```blade
@error('idempotency')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror
```

Translations ship in English and Spanish and are published with:

```bash
php artisan vendor:publish --tag=idempotency-lang
```

#### Waiting for an in-progress submission

`wait_for_completion` (default `0`, seconds) waits briefly instead of
returning an immediate `409` when the same key is already being processed —
useful for a genuine double-click, where the first submission usually
finishes within a second or two. While waiting, the request polls the store
every 100 ms and replays the response the moment the original stores it; a
replay needs no lock, so any number of concurrent duplicates are answered at
once rather than one after another. If the original fails and frees the key
instead, the waiting request executes it. Keep the wait small (1-3 seconds)
on web workers; if it times out, the request falls back to the same
in-progress rejection described above.

#### TTL

Form routes are a good fit for a shorter, generous retention than the
24-hour API default — long enough to cover a double-click or an accidental
reload, short enough that a genuinely new submission a day later isn't
mistaken for a retry:

```php
Route::post('/orders', ...)->middleware('idempotency:3600'); // 1 hour
```

#### Inertia, Livewire, and Filament

Inertia requests carry the `Idempotency-Key` header like any other client —
generate one UUID per form instance (e.g. on mount) and send it with the
request, the same as a JSON API consumer would; the `input` field fallback
above is not needed. Livewire and Filament actions are dispatched over JSON
and Livewire already guards against double-submission on the client side, so
they are out of scope for this feature.

### Requiring a key on specific routes

Leave `require_key` disabled globally (the default) and require a key only on
the routes that need it by adding `required` as a middleware parameter:

```php
Route::post('/payments', ...)->middleware('idempotency:required');       // key required, default TTL
Route::post('/imports', ...)->middleware('idempotency:3600,required');   // key required, 1 hour TTL
```

A keyless request on a `required` route is rejected with `400` before doing
any work, regardless of the global `require_key` setting. Set `require_key` to
`true` instead if every protected route must carry a key.

### Lock timeout

`lock_timeout` (or `IDEMPOTENCY_LOCK_TIMEOUT`) bounds how long a request may
hold its key's lock. A request that runs longer than this may be executed
twice by a concurrent retry that acquires the lock after it expires — size it
comfortably above your slowest guarded request.

### Replay event

A `Webrek\Idempotency\Events\IdempotentReplay` event is dispatched every time a
stored response is replayed, so you can measure how many retries you are
absorbing:

```php
use Webrek\Idempotency\Events\IdempotentReplay;

Event::listen(IdempotentReplay::class, function (IdempotentReplay $event) {
    Metrics::increment('idempotency.replays', tags: ['key' => $event->key]);
});
```

### Storage failure event

`Webrek\Idempotency\Events\IdempotencyStorageFailed` is dispatched when the
response could not be stored (`operation === 'put'`) or the key's lock could not
be released (`operation === 'release'`) after the request already ran. The
exception is also reported through your exception handler. Alert on this event:
while it fires, retries are executing again instead of being replayed.

```php
Event::listen(IdempotencyStorageFailed::class, function (IdempotencyStorageFailed $event) {
    Log::critical('idempotency store unavailable', [
        'operation' => $event->operation,
        'key' => $event->key,
        'exception' => $event->exception->getMessage(),
    ]);
});
```

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
