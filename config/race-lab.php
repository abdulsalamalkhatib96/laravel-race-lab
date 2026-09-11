<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Coordinator
    |--------------------------------------------------------------------------
    | file: zero-dependency local/CI coordinator using flock + atomic JSON state.
    | redis: shared coordination suitable for containerized CI or multi-host labs.
    */
    'coordinator' => env('RACE_LAB_COORDINATOR', 'file'),

    'storage_path' => env('RACE_LAB_STORAGE_PATH', storage_path('framework/race-lab')),
    'poll_interval_microseconds' => (int) env('RACE_LAB_POLL_US', 10_000),

    'redis' => [
        'connection' => env('RACE_LAB_REDIS_CONNECTION', 'default'),
        'prefix' => env('RACE_LAB_REDIS_PREFIX', 'race-lab:'),
        'ttl_seconds' => (int) env('RACE_LAB_REDIS_TTL', 600),
    ],

    'timeouts' => [
        'scenario' => (float) env('RACE_LAB_SCENARIO_TIMEOUT', 10),
        'worker' => (float) env('RACE_LAB_WORKER_TIMEOUT', 8),
        'barrier' => (float) env('RACE_LAB_BARRIER_TIMEOUT', 5),
    ],

    'database' => [
        'capture_queries' => env('RACE_LAB_CAPTURE_QUERIES', true),
        'capture_bindings' => env('RACE_LAB_CAPTURE_BINDINGS', false),
        'redact_bindings' => env('RACE_LAB_REDACT_BINDINGS', true),
    ],

    'trace' => [
        // Failed runs are always preserved for diagnosis/replay.
        'persist_successful_runs' => env('RACE_LAB_PERSIST_SUCCESS', false),
    ],

    // When true, worker closure captures are restricted to scalar/array data.
    // Recommended for teams that want to prevent stale Model/service captures.
    'strict_closure_captures' => env('RACE_LAB_STRICT_CAPTURES', false),

    // Prevent DatabaseTransactions / transactional RefreshDatabase from creating false tests.
    'guard_parent_transactions' => env('RACE_LAB_GUARD_PARENT_TRANSACTIONS', true),

    // Intentionally hard-off by default. Race tests mutate shared resources concurrently.
    'allow_production' => env('RACE_LAB_ALLOW_PRODUCTION', false),
];
