# Design: in-progress marker (planned for 1.5.0)

Status: proposal, not implemented. Written 2026-09-19 after validating 1.3.0 in a
production-like sandbox (nginx + php-fpm + Redis).

## Problem

Single execution in 1.x rests entirely on the atomic lock, which lives for
`lock_timeout` seconds (default 10). The sandbox reproduced the consequence:
with `lock_timeout = 4` and a request that takes 6 s, a retry sent at 5 s
acquires the expired lock, finds nothing stored, and runs the controller a
second time. Two rows, two side effects, one key.

The lock cannot tell two situations apart:

| Situation | What we want | What the lock gives |
| --- | --- | --- |
| Original request is still running past `lock_timeout` | `409`, keep waiting | re-execution |
| Worker died mid-request (OOM kill, deploy restart) | re-execute after a bounded wait | re-execution after `lock_timeout` |

Raising `lock_timeout` narrows the first case but lengthens the second, and
operators have to guess the slowest request in advance.

## Goal

A retry that arrives while the original is still running must get `409`
regardless of how long the original takes. A retry after the original died must
be able to run again after a bounded, configurable wait. Completed keys keep
replaying exactly as today.

## Proposal: two-phase state, written with `Cache::add`

Replace "lock, then execute, then put" with an explicit state machine stored
under the same cache key:

```
(absent) --add(in_progress)--> in_progress --put(completed)--> completed
                                   |
                                   +--forget()--> (absent)     on 5xx / non-cacheable
                                   +--TTL expiry--> (absent)   worker died
```

`Cache::add()` is atomic on every store Laravel ships (Redis `SET NX EX`,
Memcached `add`, DynamoDB conditional put, database insert, array, file with
`LOCK_EX`). It can therefore replace the lock entirely: whoever wins the `add`
owns the execution, and the marker itself is the guard for as long as it lives.

### Request flow

1. Resolve key, cache key and fingerprint exactly as in 1.3.
2. `state = repository->get(cacheKey)`:
   - `completed` → fingerprint check → replay (unchanged).
   - `in_progress` → fingerprint check → `422` on mismatch, else `409` with
     `Retry-After`.
   - absent → continue.
3. `repository->start(cacheKey, fingerprint, in_progress_ttl)` performs the
   atomic `add`. If it returns `false`, another request won the race: go back
   to step 2 once (it is either `in_progress` → `409`, or already `completed`
   → replay).
4. Run `$next($request)`.
5. Cacheable response → `put(completed)` with the normal `ttl` (overwrites the
   marker). Non-cacheable (5xx, 408/425/429, oversized, streamed) → `forget()`
   so the next retry may execute.
6. Exception escaping the controller → `forget()` in `finally`, same as a
   non-cacheable response.
7. Hard crash (no `finally` runs) → the marker expires after
   `in_progress_ttl`; until then retries get `409`.

Steps 5–7 keep the 1.3 storage-failure behaviour: if `put`/`forget` throw
after the controller ran, report, fire `IdempotencyStorageFailed`, return the
fresh response. The marker then lingers until `in_progress_ttl`, so retries get
`409` for that window instead of re-executing; document this.

### Semantics compared with 1.3

| Situation | 1.3 (lock) | 1.4 (marker) |
| --- | --- | --- |
| Retry while original runs, under `lock_timeout` | `409` | `409` |
| Retry while original runs, past `lock_timeout` | **re-executes** | `409` |
| Retry after worker crash | re-executes after `lock_timeout` (10 s) | re-executes after `in_progress_ttl` |
| Retry after 5xx / non-cacheable | re-executes | re-executes |
| Retry after completion | replay | replay |
| Store unreachable before execution | `500`, nothing ran | `500`, nothing ran |
| Store unreachable after execution | fresh response, retry re-executes | fresh response, retry gets `409` until marker expires |
| Cache store requirement | must implement `LockProvider` | any store (`add` is universal) |

### Configuration

```php
// Upper bound on how long a request may be "in progress" before a retry is
// allowed to execute it again. Must exceed your slowest guarded request; it is
// also the longest a crashed worker can block retries of one key.
'in_progress_ttl' => (int) env('IDEMPOTENCY_IN_PROGRESS_TTL', 120),

// Optional: instead of an immediate 409, poll the store for up to this many
// seconds and return the replay if the original finishes in time. 0 disables
// it. Handy for double-clicks; keep it small (1–3 s) on web workers.
'wait_for_completion' => 0,
```

`lock_timeout` becomes unused by the default repository and is removed in 2.0.
Keep reading it in 1.4 for custom repositories still on the lock path.

### Retry-After

For `in_progress`, compute `Retry-After` as `max(1, min(remaining marker TTL,
5))` so clients back off sensibly without waiting the full window. Requires the
marker to carry `started_at`.

### Data model

Stored value becomes a tagged array:

```php
['state' => 'in_progress', 'fingerprint' => ..., 'started_at' => 1789801191]
['state' => 'completed', 'status' => 201, 'body' => ..., 'headers' => [...], 'fingerprint' => ...]
```

`StoredResponse::fromArray` keeps accepting the untagged 1.x shape so a rolling
deploy that mixes 1.3 and 1.4 workers still replays existing entries.

### Contract changes without a major bump

`IdempotencyRepository` cannot gain methods without breaking custom
implementations. Add a second interface:

```php
interface SupportsInProgressMarkers
{
    /** Atomically claim execution of $key. False when something is already stored. */
    public function start(string $key, string $fingerprint, int $ttl): bool;

    /** @return StoredResponse|InProgress|null */
    public function state(string $key): StoredResponse|InProgress|null;
}
```

`CacheRepository` implements it. The middleware checks
`$repository instanceof SupportsInProgressMarkers` and takes the marker path;
otherwise it falls back to the 1.3 lock path unchanged. 2.0 merges the
interfaces and deletes the lock path.

### Heartbeat (deferred)

Requests longer than `in_progress_ttl` would again risk double execution. A
`Idempotency::heartbeat()` helper the controller can call to extend the marker
covers long imports without inflating the crash-recovery window for everyone.
Not needed for the first release; note it in the README as the escape hatch.

### Tests to add

- Concurrent retry past the old `lock_timeout` gets `409`, one execution.
  (Array store + `travel()`; the sandbox scenario J with `ms=6000`.)
- Marker expiry lets a retry execute after `in_progress_ttl`.
- 5xx and exceptions remove the marker; the next retry executes.
- `start()` race: two callers, exactly one `true` (mock the store or use the
  array driver with a spy).
- Rolling-deploy compatibility: an untagged 1.x entry still replays.
- `wait_for_completion` returns the replay when the original completes inside
  the window, `409` otherwise.
- Custom repository without `SupportsInProgressMarkers` still uses the lock
  path (regression guard for the fallback).

### Rollout

1. Ship behind `SupportsInProgressMarkers` with the default repository opted in.
2. Sandbox scenario J must flip from "2 rows" to "1 row + 409".
3. Release 1.4.0; deprecate `lock_timeout` in the changelog; remove in 2.0.
