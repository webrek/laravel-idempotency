# Changelog

All notable changes to `webrek/laravel-idempotency` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `idempotency` middleware that replays the original response for a repeated
  `Idempotency-Key` and serialises concurrent duplicates with an atomic lock.
- Request fingerprinting to reject a key reused with a different payload (`422`).
- Configurable header, guarded methods, retention, lock timeout, key scoping,
  replayable status codes and persisted headers.
- Cache-backed storage with no migrations.
