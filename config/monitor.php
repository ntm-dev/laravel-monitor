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
    | Session & CSRF Cookies
    |--------------------------------------------------------------------------
    |
    | Names of the cookies the dashboard's own session/CSRF token run under —
    | deliberately distinct from the host app's `session.cookie` and its
    | (framework-hardcoded) `XSRF-TOKEN` cookie. Without this, logging out of
    | the host app (which typically invalidates its entire session) can take
    | the monitor guard's login down with it, and the two apps' CSRF tokens
    | overwrite each other's XSRF-TOKEN cookie, producing 419s.
    |
    */

    'session' => [
        'cookie' => env('MONITOR_SESSION_COOKIE', 'monitor_session'),
        'xsrf_cookie' => env('MONITOR_XSRF_COOKIE', 'monitor_xsrf_token'),
    ],

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
    | Storage
    |--------------------------------------------------------------------------
    |
    | Captured entries are persisted to a database table (works with MySQL,
    | PostgreSQL and SQLite) via the Database*Storage classes bound in
    | MonitorServiceProvider::registerBindings() — connection/table are
    | shared across all of them.
    |
    */

    'storage' => [
        'database' => [
            'connection' => env('MONITOR_DB_CONNECTION'),
            'table' => 'monitor_entries',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | The package's own login system for the dashboard — independent of the
    | host app's own Auth guards (Laravel isolates session state per guard
    | name automatically). `table` names the users table, auto-migrated
    | alongside the rest of the package's tables.
    |
    */

    'auth' => [
        'guard' => 'monitor',
        'table' => 'monitor_users',
        'invitations_table' => 'monitor_invitations',
        'password_resets_table' => 'monitor_password_resets',
        'email_changes_table' => 'monitor_email_changes',
        'webauthn_table' => 'monitor_webauthn_credentials',
        'oauth_accounts_table' => 'monitor_oauth_accounts',
        'oauth' => [
            'google' => [
                'client_id' => env('MONITOR_GOOGLE_CLIENT_ID'),
                'client_secret' => env('MONITOR_GOOGLE_CLIENT_SECRET'),
                'redirect' => env('MONITOR_GOOGLE_REDIRECT_URI'),
            ],
            'apple' => [
                'client_id' => env('MONITOR_APPLE_CLIENT_ID'),
                'client_secret' => env('MONITOR_APPLE_CLIENT_SECRET'),
                'key_id' => env('MONITOR_APPLE_KEY_ID'),
                'team_id' => env('MONITOR_APPLE_TEAM_ID'),
                'private_key' => env('MONITOR_APPLE_PRIVATE_KEY'),
                'redirect' => env('MONITOR_APPLE_REDIRECT_URI'),
            ],
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

    'issues' => [
        'table' => 'monitor_issues',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Thresholds
    |--------------------------------------------------------------------------
    |
    | Requests, jobs, queries and outgoing requests at or above these
    | durations (milliseconds) are shown as "over threshold" on the
    | dashboard, one threshold card per monitored area.
    |
    */

    'thresholds' => [
        'request' => env('MONITOR_REQUEST_THRESHOLD', 1000),
        'job' => env('MONITOR_JOB_THRESHOLD', 1000),
        'command' => env('MONITOR_COMMAND_THRESHOLD', 1000),
        'query' => env('MONITOR_QUERY_THRESHOLD', 500),
        'outgoing_request' => env('MONITOR_OUTGOING_REQUEST_THRESHOLD', 1000),
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
            // Milliseconds. Every query is recorded regardless of this
            // value — it's read live by the Query Detail page to decide
            // which calls to highlight as slow (Livewire\QueryDetail's
            // $slowThreshold), not used to tag anything at record time.
            'threshold' => env('MONITOR_SLOW_QUERY_THRESHOLD', 100),
            'ignore_paths' => [],
        ],

        Recorders\Exceptions::class => [
            'enabled' => env('MONITOR_EXCEPTIONS_ENABLED', true),
        ],

        Recorders\Logs::class => [
            'enabled' => env('MONITOR_LOGS_ENABLED', true),
            'levels' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'],
            'ignore_paths' => [],
        ],

        Recorders\Jobs::class => [
            'enabled' => env('MONITOR_JOBS_ENABLED', true),
        ],

        Recorders\ScheduledTasks::class => [
            'enabled' => env('MONITOR_SCHEDULE_ENABLED', true),
        ],

        Recorders\Commands::class => [
            'enabled' => env('MONITOR_COMMANDS_ENABLED', true),
        ],

        // Hydrated-model counter (every request/job/command) plus lazy-
        // loading (N+1) violations — the latter only ever fires for apps
        // that already call Model::preventLazyLoading()/shouldBeStrict()
        // themselves; see Recorders\Models.
        Recorders\Models::class => [
            'enabled' => env('MONITOR_MODELS_ENABLED', true),
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
