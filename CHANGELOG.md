# Changelog

All notable changes to `webrek/laravel-idempotency` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-09-19

### Added

- Web forms (Blade) support: classic HTML form submissions can now opt into
  idempotency without sending a custom header.
- `input` config key (default `_idempotency_key`): when the `Idempotency-Key`
  header is absent or blank, the middleware falls back to this request field.
  Set to `null` to disable the fallback.
- `@idempotencyKey` Blade directive, rendering a hidden input carrying a fresh
  UUID for the field above.
- `replay_flash` config key (default `true`): a replayed response now
  re-flashes the session data (errors, old input, status) captured when it
  was first stored, so a replayed validation redirect still shows its errors
  and old input, and a replayed success redirect still shows its status.
  Error bags are stored as plain arrays and rebuilt on replay, so this works
  with `cache.serializable_classes => false` (Laravel's default); other
  objects flashed via `with()` are not replayed.
- `redirect_back` and `error_key` config keys (defaults `true` and
  `idempotency`): the four client-facing rejections (missing key, invalid
  key, conflict, in-progress) now redirect back with the input re-flashed and
  a translated message under `error_key`, instead of throwing, whenever the
  request carries a session and does not expect JSON. Translations are
  published under the `idempotency-lang` tag (English and Spanish included).
- `wait_for_completion` config key (default `0`): instead of an immediate
  `409` when a key is already in progress, wait up to this many seconds,
  polling the store, and replay the response as soon as the original request
  stores it. Replays need no lock, so concurrent duplicates are all answered
  at once.

### Changed

- `StoredResponse` gained a fifth constructor parameter, `array $flash`,
  included in `toArray()`/`fromArray()`; entries stored by 1.3.x still
  replay correctly during a rolling deploy (`flash` defaults to `[]`).
- `EnsureIdempotency`'s constructor gained `Illuminate\Routing\Redirector`
  and `Illuminate\Contracts\Translation\Translator` dependencies.

## [1.3.0] - 2026-09-19

### Added

- `never_replay_status_codes` (default `[408, 425, 429]`): transient client
  errors are no longer stored for the whole TTL — the next attempt re-runs
  them, unless `replay_status_codes` explicitly allowlists that code.
- `max_body_size` (default 1 MiB, 0 disables it): responses larger than this
  are never stored, so an oversized reply cannot exhaust your cache.
- `idempotency:required` middleware parameter (also `idempotency:3600,required`)
  to require a key on specific routes without flipping the global
  `require_key` setting.
- `IDEMPOTENCY_LOCK_TIMEOUT` environment variable for `lock_timeout`.
- `IdempotencyStorageFailed` event, dispatched when storing the response or
  releasing the lock fails after the request already ran.

### Changed

- **Cache keys and fingerprints are derived differently, so responses stored
  by 1.2.x are not replayed after upgrading; a retry of a request that was in
  flight during the deploy will execute again once.**
- The request fingerprint now covers the full URI (including the query
  string) instead of just the path. When the raw body is unavailable — as
  with `multipart/form-data`, which PHP does not expose through `php://input`
  — it falls back to the parsed input fields (order-independent) and the
  uploaded files' names, sizes and content hashes instead of an empty string.
- The cache key is now derived from a hashed, namespaced representation of the
  key (and, when `scope_by_user` applies, the user's class and id), so a key
  containing the literal separator sequence used internally for user-scoping
  can no longer collide with another caller's or user's entry.
- `Location` is now always included in the persisted headers, even if removed
  from `persist_headers`, so replayed redirects and `201 Created` responses
  keep their `Location` header.
- `EnsureIdempotency` now receives an `Illuminate\Contracts\Events\Dispatcher`
  via constructor injection and dispatches `IdempotentReplay` through it
  instead of the `event()` global helper.

### Fixed

- A cache failure *after* the controller ran (storing the response or
  releasing the lock) no longer surfaces as a `500` for work that already
  succeeded: the fresh response is returned, the exception is reported and
  `IdempotencyStorageFailed` fires. The retry executes again because nothing
  was stored.
- Replayed redirects and `201` responses no longer lose their `Location`
  header.
- The fingerprint no longer ignores the query string, so requests differing
  only by query string are no longer treated as identical.
- The fingerprint no longer silently degrades to an empty raw body for
  `multipart/form-data` requests, so a differing multipart payload (fields or
  file contents) is now correctly rejected with `422` instead of being
  replayed.
- A guest sending a literal key such as `abc|u:5` can no longer land on user
  `5`'s entry for key `abc`.
- `ttl` and `lock_timeout` of `0` or a negative number now raise an
  `InvalidArgumentException` instead of silently disabling the TTL or the lock
  expiry.
- `illuminate/routing`, used directly by the service provider, is now declared
  in `composer.json`.

## [1.2.0] - 2026-06-16

### Added

- Laravel 13 support. The package now installs on Laravel 12 and 13 (PHP 8.2+).

## [1.1.0] - 2026-06-07

### Added

- `IdempotentReplay` event, fired whenever a stored response is replayed —
  useful for metrics on how many retries you are absorbing.
- Per-route retention: pass a TTL (seconds) as a middleware parameter,
  `->middleware('idempotency:3600')`, overriding the configured default.

## [1.0.0] - 2026-06-07

### Added

- `idempotency` middleware that replays the original response for a repeated
  `Idempotency-Key` and serialises concurrent duplicates with an atomic lock.
- Request fingerprinting to reject a key reused with a different payload (`422`).
- Configurable header, guarded methods, retention, lock timeout, key scoping,
  replayable status codes and persisted headers.
- Cache-backed storage with no migrations.
