# Changelog

All notable changes to `webrek/laravel-idempotency` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
