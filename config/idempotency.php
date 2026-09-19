<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotency header
    |--------------------------------------------------------------------------
    |
    | The request header clients send to identify a retryable operation. A
    | client picks a unique value (typically a UUID) per logical request and
    | reuses it when retrying after a timeout or network failure. Stripe, for
    | example, calls this "Idempotency-Key".
    |
    */

    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),

    /*
    |--------------------------------------------------------------------------
    | Require a key
    |--------------------------------------------------------------------------
    |
    | When false, requests without the header pass straight through untouched,
    | which lets you opt routes in without breaking existing callers. Set it to
    | true to reject keyless requests on the guarded routes with a 400.
    |
    */

    'require_key' => false,

    /*
    |--------------------------------------------------------------------------
    | Guarded methods
    |--------------------------------------------------------------------------
    |
    | Only these HTTP methods are intercepted. GET and HEAD are already safe to
    | repeat, so there is no reason to spend storage replaying them.
    |
    */

    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | The cache store used to persist replayed responses and the per-key locks.
    | Null falls back to your default store. The store MUST support atomic locks
    | (redis, memcached, dynamodb, database, file and array all do); a store
    | without locks cannot guarantee single execution under concurrency.
    |
    */

    'store' => env('IDEMPOTENCY_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Key prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => 'idempotency:',

    /*
    |--------------------------------------------------------------------------
    | Retention (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a stored response stays replayable. After this window the key is
    | forgotten and the same key would execute again. 24 hours is a sane default
    | that comfortably covers client retry windows.
    |
    */

    'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Lock timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Upper bound a single request may hold its key's lock. It must outlast your
    | slowest guarded request, but stay short enough that a crashed worker frees
    | the key for a legitimate retry instead of wedging it. A request that runs
    | longer than this may be executed twice by a concurrent retry; size it
    | above your slowest guarded request.
    |
    */

    'lock_timeout' => (int) env('IDEMPOTENCY_LOCK_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Maximum key length
    |--------------------------------------------------------------------------
    |
    | Keys longer than this are rejected with a 400 before any work is done, so
    | a caller cannot exhaust your cache with oversized keys.
    |
    */

    'max_key_length' => 255,

    /*
    |--------------------------------------------------------------------------
    | Scope keys by authenticated user
    |--------------------------------------------------------------------------
    |
    | When true the stored key is namespaced by the authenticated user id, so
    | one caller can never replay (or collide with) another caller's response by
    | guessing a key. Disable only if keys are already globally unique.
    |
    */

    'scope_by_user' => true,

    /*
    |--------------------------------------------------------------------------
    | Replayable status codes
    |--------------------------------------------------------------------------
    |
    | Null stores every response below 500 — successes and deterministic client
    | errors are replayed, while server errors are left uncached so the caller
    | can safely retry them. Provide an explicit array (e.g. [200, 201, 422]) to
    | replay only those codes.
    |
    */

    'replay_status_codes' => null,

    /*
    |--------------------------------------------------------------------------
    | Never-replay status codes
    |--------------------------------------------------------------------------
    |
    | Transient client errors that must never be stored, even though they are
    | below 500 — the caller is expected to retry them, and a request that
    | briefly fails with one of these should not be pinned to that outcome for
    | the whole TTL. Only consulted when `replay_status_codes` is null; an
    | explicit `replay_status_codes` allowlist always wins.
    |
    */

    'never_replay_status_codes' => [408, 425, 429],

    /*
    |--------------------------------------------------------------------------
    | Maximum stored body size (bytes)
    |--------------------------------------------------------------------------
    |
    | Responses larger than this are never stored, so a single oversized reply
    | cannot exhaust your cache. Set to 0 to disable the limit.
    |
    */

    'max_body_size' => 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Persisted response headers
    |--------------------------------------------------------------------------
    |
    | Headers copied onto the replayed response. Keep this tight — there is no
    | need to replay per-request headers such as Date or Set-Cookie. `Location`
    | is always persisted (redirects and `201 Created` responses need it to
    | replay correctly) even if it is removed from this list.
    |
    */

    'persist_headers' => ['Content-Type', 'Location'],

    /*
    |--------------------------------------------------------------------------
    | Replay marker header
    |--------------------------------------------------------------------------
    |
    | Added to every guarded response: "true" when served from the store,
    | "false" when freshly computed. Handy for clients and dashboards.
    |
    */

    'replay_header' => 'Idempotency-Replayed',

];
