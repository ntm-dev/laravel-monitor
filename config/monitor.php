<?php

use LaravelMonitor\Recorders;

return [

    /*
    |--------------------------------------------------------------------------
    | Monitor Master Switch
    |--------------------------------------------------------------------------
    |
    | Disable this to stop all recording without removing the package. The
    | dashboard stays reachable so historical data can still be browsed.
    |
    */

    'enabled' => env('MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Route
    |--------------------------------------------------------------------------
    */

    'domain' => env('MONITOR_DOMAIN'),

    'path' => env('MONITOR_PATH', 'monitor'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Periods & Refresh
    |--------------------------------------------------------------------------
    |
    | Preset time ranges offered by the dashboard (key => hours) and how
    | often the Livewire cards poll for fresh data, in seconds. Arbitrary
    | ranges can also be picked from the calendar popover.
    |
    */

    'periods' => [
        '1h' => 1,
        '24h' => 24,
        '7d' => 168,
        '14d' => 336,
        '30d' => 720,
    ],

    'refresh' => env('MONITOR_REFRESH', 10),

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Where captured entries are persisted. "database" ships with the package
    | and works with MySQL, PostgreSQL and SQLite. Register your own driver
    | with LaravelMonitor\StorageManager::extend() to add more.
    |
    */

    'storage' => [
        'driver' => env('MONITOR_STORAGE_DRIVER', 'database'),

        'database' => [
            'connection' => env('MONITOR_DB_CONNECTION'),
            'table' => 'monitor_entries',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | The `monitor:prune` command deletes entries older than this many hours.
    | Schedule it daily in your console kernel / routes.
    |
    */

    'retention' => [
        'hours' => env('MONITOR_RETENTION_HOURS', 168),
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggregates
    |--------------------------------------------------------------------------
    |
    | The `monitor:aggregate` command rolls raw entries up into fixed-width
    | count buckets, so the dashboard's unfiltered trend charts (Overview,
    | Requests, Cache, ...) read this much smaller table instead of scanning
    | every raw row on every page load. Schedule it to run about once every
    | `period` seconds in your console kernel / routes — each run covers
    | exactly one bucket, so it needs to run at roughly that cadence to stay
    | caught up. Charts filtered to a single route/job/user still scan raw
    | entries directly; aggregates only ever back the unfiltered totals.
    |
    */

    'aggregates' => [
        'table' => 'monitor_aggregates',
        'period' => env('MONITOR_AGGREGATE_PERIOD', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Thresholds
    |--------------------------------------------------------------------------
    |
    | Requests and jobs at or above these durations (milliseconds) are shown
    | as "over threshold" on the dashboard, mirroring Nightwatch's routes /
    | jobs threshold cards.
    |
    */

    'thresholds' => [
        'request' => env('MONITOR_REQUEST_THRESHOLD', 1000),
        'job' => env('MONITOR_JOB_THRESHOLD', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingest Buffer
    |--------------------------------------------------------------------------
    |
    | Entries are buffered in memory and written in one batch when the request
    | (or queue job) finishes, or as soon as the buffer reaches this size.
    |
    */

    'buffer' => env('MONITOR_BUFFER', 200),

    /*
    |--------------------------------------------------------------------------
    | Recorders
    |--------------------------------------------------------------------------
    |
    | Each recorder listens for framework events and turns them into monitor
    | entries. Disable any recorder or tune its options here.
    |
    */

    'recorders' => [

        Recorders\Requests::class => [
            'enabled' => env('MONITOR_REQUESTS_ENABLED', true),
            'ignore_paths' => [
                'livewire*',
                '_debugbar*',
                'telescope*',
                'pulse*',
                'horizon*',
            ],
        ],

        // Env var names keep their historical "slow query" wording even
        // though this recorder now captures every query — renaming them
        // would silently drop any existing .env override.
        Recorders\Queries::class => [
            'enabled' => env('MONITOR_SLOW_QUERIES_ENABLED', true),
            // Milliseconds. Queries at or above this threshold are tagged
            // `slow` (surfaced in the dedicated Slow Queries digest) rather
            // than `fast` — every query is recorded either way.
            'threshold' => env('MONITOR_SLOW_QUERY_THRESHOLD', 100),
        ],

        Recorders\Exceptions::class => [
            'enabled' => env('MONITOR_EXCEPTIONS_ENABLED', true),
        ],

        Recorders\Logs::class => [
            'enabled' => env('MONITOR_LOGS_ENABLED', true),
            'levels' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'],
        ],

        Recorders\Jobs::class => [
            'enabled' => env('MONITOR_JOBS_ENABLED', true),
        ],

        Recorders\ScheduledTasks::class => [
            'enabled' => env('MONITOR_SCHEDULE_ENABLED', true),
        ],

        Recorders\CacheInteractions::class => [
            'enabled' => env('MONITOR_CACHE_ENABLED', true),
            'ignore_keys' => [
                'illuminate:*',
                'laravel:pulse:*',
                'telescope:*',
                'framework/schedule*',
                '*livewire*',
            ],
        ],

        Recorders\OutgoingRequests::class => [
            'enabled' => env('MONITOR_OUTGOING_ENABLED', true),
        ],

        Recorders\Notifications::class => [
            'enabled' => env('MONITOR_NOTIFICATIONS_ENABLED', true),
        ],

        Recorders\Mail::class => [
            'enabled' => env('MONITOR_MAIL_ENABLED', true),
        ],

        Recorders\Authentication::class => [
            'enabled' => env('MONITOR_AUTH_ENABLED', true),
        ],

    ],

];
