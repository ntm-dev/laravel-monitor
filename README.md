# Laravel Monitor

Local-first application monitoring for Laravel — Nightwatch-style insights on a self-hosted dashboard like Pulse. No external service, no agent, no fee: everything is captured from framework events and stored in your own database.

**What it monitors:**

| Card | Source |
| --- | --- |
| Requests (count, avg/max time, status) | `RequestHandled` |
| Slow queries (with app-code location) | `QueryExecuted` over a threshold |
| Exceptions (grouped by fingerprint, handled/unhandled, occurrence timeline, Ignition-style stack trace + detail page) | `MessageLogged` |
| Logs (filterable by level) | `MessageLogged` |
| Queue jobs (queued / processed / failed, runtime) | queue events |
| Scheduled tasks (finished / failed / skipped) | scheduler events |
| Cache (hit rate, writes, busiest keys) | cache events |
| Outgoing HTTP (count, errors, avg time) | HTTP client events |
| Mail & notifications | mail / notification events |
| Users (most active, recent logins) | auth events + request attribution |

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12 or 13
- Livewire 3 or 4 (installed automatically)

## Installation

```bash
composer require ntm-dev/laravel-monitor
php artisan migrate
```

That's it. Open `/monitor` in your browser (allowed automatically in the `local` environment).

Optionally publish the config and views:

```bash
php artisan vendor:publish --tag=monitor-config
php artisan vendor:publish --tag=monitor-views
```

## Dashboard authorization

Outside the `local` environment the dashboard returns 403 by default. Grant access by defining the gate in a service provider:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewMonitor', function ($user = null) {
    return $user?->isAdmin() ?? false;
});
```

## Configuration

All options live in `config/monitor.php`. Highlights:

```php
'enabled' => env('MONITOR_ENABLED', true),   // master switch
'path' => env('MONITOR_PATH', 'monitor'),    // dashboard URL
'retention' => ['hours' => 168],             // used by monitor:prune

'recorders' => [
    Recorders\SlowQueries::class => [
        'enabled' => true,
        'threshold' => 100, // ms
    ],
    // ... every recorder can be disabled or tuned individually
],
```

## Pruning old data

Schedule the prune command so the table doesn't grow forever:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
Schedule::command('monitor:prune')->daily();
```

`php artisan monitor:clear` wipes everything.

## Aggregating trend data

`monitor:aggregate` rolls raw entries up into fixed-width buckets
(`monitor_aggregates`) — count, plus sum/max/min of `duration` — so the
dashboard's unfiltered trend charts *and* headline totals (Overview,
Requests, Application, Exceptions, ...) read that much smaller table instead
of scanning every raw row on every page load. Schedule it to run about once
every `monitor.aggregates.period` seconds (60 by default) — each run only
covers one bucket, so it needs to run at roughly that cadence to stay caught
up:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
Schedule::command('monitor:aggregate')->everyMinute();
```

Charts and totals filtered to a single route/job/user still scan raw entries
directly — aggregates only ever back the unfiltered case. Until this is
scheduled (or for any range older than the aggregator has backfilled), those
reads fall back to scanning raw entries directly rather than under-reporting
— slower, but never silently wrong.

## Storage drivers

The default `database` driver stores entries in a `monitor_entries` table (MySQL, PostgreSQL, SQLite). Point it at a separate connection to keep monitoring data out of your main database:

```env
MONITOR_DB_CONNECTION=monitor_sqlite
```

Custom drivers implement `LaravelMonitor\Contracts\Storage` and are registered in a service provider:

```php
use LaravelMonitor\StorageManager;

public function boot(): void
{
    $this->app->make(StorageManager::class)->extend('redis', function ($app) {
        return new RedisStorage($app['redis']);
    });
}
```

Then set `MONITOR_STORAGE_DRIVER=redis`.

## Recording custom entries

```php
use LaravelMonitor\Facades\Monitor;

Monitor::record(
    type: 'deployment',
    key: 'v1.4.2',
    payload: ['by' => 'manh'],
);

// Run something without it being monitored:
Monitor::ignore(fn () => Cache::get('secret'));
```

## How it works

Recorders subscribe to framework events and buffer entries in memory. The buffer is flushed in a single batch when the request (or queue job / scheduled task) finishes, so monitoring adds no queries during the request itself. Recording is paused while flushing, so the monitor never observes its own writes.

## Testing

```bash
composer install
composer test
```

## License

MIT
