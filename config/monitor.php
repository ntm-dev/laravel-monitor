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

        Recorders\SlowQueries::class => [
            'enabled' => env('MONITOR_SLOW_QUERIES_ENABLED', true),
            // Milliseconds. Queries at or above this threshold are recorded.
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
