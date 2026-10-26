# Laravel Monitor

Local-first application monitoring for Laravel. Requests, queries, jobs, commands, scheduled tasks,
exceptions, cache, mail, notifications and outgoing HTTP are captured from framework events, stored
in your own database, and browsed on a self-hosted Livewire dashboard. No external service, no
agent, no fee.

## Features

**Dashboard tabs**

| Tab | What you get |
| --- | --- |
| Overview | Headline totals and trend charts across every area |
| Issues | Exceptions and slow requests/jobs/queries/commands/outgoing calls, grouped into issues you can mark open / resolved / ignored |
| Requests | Per-route count, duration and status; per-request detail with a lifecycle timeline (bootstrap, middleware, controller, render, terminating) and every query, job, cache call, log and exception it triggered |
| Jobs | Queued / processed / released / failed per job class, per-attempt detail and timeline |
| Commands / Schedule | Artisan commands and scheduled tasks (finished / failed / skipped) with per-run timelines |
| Exceptions | Grouped by fingerprint, handled vs. unhandled, occurrence chart, stack trace with source |
| Queries | Every query grouped by SQL shape, with the app-code location that ran it; failed statements too |
| Mail / Notifications | Per-class and per-send detail |
| Cache | Hit rate, writes, busiest keys |
| Outgoing | HTTP client calls per domain, errors, duration |
| Users | Most active users, logins, per-user activity |
| Logs | Log entries, filterable by level |
| Settings | Recorder toggles, thresholds, retention, team members, account security |

Also recorded: Eloquent models hydrated per request/job/command, and lazy-loading (N+1)
violations for apps that already enable `Model::preventLazyLoading()`.

**Dashboard accounts** — the dashboard has its own users, separate from your app's: owner / admin
/ viewer roles, email invitations, password reset, and optional TOTP two-factor, passkeys and
Google / Apple sign-in.

The UI ships in English and Vietnamese; each viewer picks language, theme and timezone.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12 or 13
- Livewire 3 or 4 (installed automatically)
- MySQL, PostgreSQL or SQLite

## Installation

```bash
composer require ntm-dev/laravel-monitor
php artisan migrate
```

Then create the first dashboard account:

1. Open `/monitor` in your browser. With no account yet, you're sent to the setup page.
2. Prove you have server access by generating a one-time code (valid for 10 minutes):

   ```bash
   php artisan monitor:setup-code
   ```

3. Paste the code, then create the **owner** account. Invite the rest of your team from
   Settings → Team.

Invitation and password-reset emails go through your app's own mailer.

Optionally publish package resources:

```bash
php artisan vendor:publish --tag=monitor-config
php artisan vendor:publish --tag=monitor-views
php artisan vendor:publish --tag=monitor-lang
php artisan vendor:publish --tag=monitor-migrations
```

## Access control

Two layers protect the dashboard:

1. **The `viewMonitor` gate** runs on every dashboard route, login pages included. By default it
   only allows the `local` environment, so production returns 403 until you define it — for
   example by IP or by a host-app user:

   ```php
   use Illuminate\Support\Facades\Gate;

   Gate::define('viewMonitor', function ($user = null) {
       return request()->ip() === '203.0.113.10';
   });
   ```

2. **The dashboard login** (guard `monitor`, users in `monitor_users`) is required for everything
   past the gate. The package registers this guard and its provider itself, unless your app already
   defines a guard named `monitor`.

The dashboard runs on its own session and CSRF cookies (`monitor_session`, `monitor_xsrf_token`),
so logging out of your app doesn't log you out of the dashboard and the two never overwrite each
other's tokens.

### Optional sign-in methods

Each method appears on the login page only once its dependencies are installed:

| Method | Install | Configure |
| --- | --- | --- |
| TOTP two-factor | `pragmarx/google2fa`, `bacon/bacon-qr-code` | — (enabled per user in Settings) |
| Passkeys (WebAuthn) | `web-auth/webauthn-lib` | — (the dashboard must be served from a domain name, not an IP) |
| Google | `laravel/socialite` | `MONITOR_GOOGLE_CLIENT_ID`, `MONITOR_GOOGLE_CLIENT_SECRET`, `MONITOR_GOOGLE_REDIRECT_URI` |
| Apple | `laravel/socialite`, `socialiteproviders/apple` | `MONITOR_APPLE_CLIENT_ID`, `MONITOR_APPLE_CLIENT_SECRET`, `MONITOR_APPLE_KEY_ID`, `MONITOR_APPLE_TEAM_ID`, `MONITOR_APPLE_PRIVATE_KEY`, `MONITOR_APPLE_REDIRECT_URI` |

OAuth credentials are read from `monitor.auth.oauth.*` and only fill `services.google` /
`services.apple` when your app hasn't configured those itself.

## Scheduling

Add both commands to your scheduler (`routes/console.php` on Laravel 11+, or
`app/Console/Kernel.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('monitor:aggregate')->everyMinute();
Schedule::command('monitor:prune')->daily();
```

**`monitor:aggregate`** rolls raw entries up into fixed-width buckets (`monitor_aggregates`: count
plus sum / max / min duration). Unfiltered trend charts and headline totals read this much smaller
table instead of scanning every raw row. Each run covers one bucket of `monitor.aggregates.period`
seconds (60 by default), so run it at about that cadence.

- Charts filtered to a single route / job / user always scan raw entries.
- A range that hasn't been rolled up yet falls back to a raw scan — slower, never under-reported.
- After such a fallback, the page also rolls up to `catch_up_buckets` (30) missing buckets once the
  response is sent, so an install without a scheduler still catches up within a few page views.
  Set `MONITOR_AGGREGATE_CATCH_UP=false` to leave it to the scheduler alone.

**`monitor:prune`** deletes entries older than the retention period (`MONITOR_RETENTION_HOURS`,
168 by default; override per run with `--hours=`), deleting in batches. It also reconciles the
Issues list against what's left.

`php artisan monitor:clear` deletes every entry.

## Configuration

Defaults live in `config/monitor.php`; the most common ones are environment variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `MONITOR_ENABLED` | `true` | Master switch. When off, nothing is recorded and the dashboard isn't registered. |
| `MONITOR_PATH` / `MONITOR_DOMAIN` | `monitor` / — | Dashboard URL |
| `MONITOR_DB_CONNECTION` | default connection | Keep monitoring data out of your main database |
| `MONITOR_TABLE_PREFIX` | `monitor_` | Prefix for every table the package creates |
| `MONITOR_RETENTION_HOURS` | `168` | Used by `monitor:prune` |
| `MONITOR_REFRESH` | `10` | Dashboard polling interval, seconds |
| `MONITOR_BUFFER` | `200` | Entries buffered in memory before an early flush |
| `MONITOR_REQUEST_THRESHOLD`, `MONITOR_JOB_THRESHOLD`, `MONITOR_COMMAND_THRESHOLD`, `MONITOR_QUERY_THRESHOLD`, `MONITOR_OUTGOING_REQUEST_THRESHOLD` | `1000`, `1000`, `1000`, `500`, `1000` | Milliseconds; slower entries are flagged on the dashboard and opened as performance issues |

### Recorders

Every recorder can be disabled or tuned under `monitor.recorders`. The high-volume ones are **off
outside `local` by default**, as are the detail options that store bodies or full stack traces:

| Recorder | Default outside `local` | Detail options (off outside `local`) |
| --- | --- | --- |
| `Requests` | on | response body (`MONITOR_REQUESTS_RESPONSE_BODY_ENABLED`); bodies capped at `MONITOR_REQUESTS_MAX_BODY_BYTES` (64 KB) |
| `Queries` | **off** (`MONITOR_SLOW_QUERIES_ENABLED`) | full stack trace per query |
| `Exceptions`, `Logs`, `Commands`, `ScheduledTasks`, `Authentication` | on | — |
| `Jobs` | on | dispatch stack trace |
| `Models` | **off** (`MONITOR_MODELS_ENABLED`) | — |
| `CacheInteractions` | **off** (`MONITOR_CACHE_ENABLED`) | — |
| `OutgoingRequests` | on | stack trace, request/response body |
| `Notifications` | on | notification data |
| `Mail` | on | message body |

`Requests` skips `livewire*`, `_debugbar*`, `telescope*`, `pulse*` and `horizon*` paths, and
`CacheInteractions` skips framework / Livewire keys; both lists are configurable.

### Settings page

Owners and admins can change recorder toggles, thresholds, retention, refresh interval, dashboard
path, table prefix and the aggregate cache from **Settings** without redeploying. Saved values
override `config/monitor.php` and are written to `bootstrap/cache/monitor-settings.php` — keep that
file across deploys if you rely on it, or delete it to fall back to the config defaults.

Switching the master switch off from Settings also takes the dashboard down, so the page asks you
to type `disable` to confirm first. To turn it back on, set `'enabled' => true` in that file (or
delete the file, which resets every Settings value) — changing `MONITOR_ENABLED` alone isn't enough,
since the saved value wins. Restart Octane / PHP-FPM workers if the change doesn't show up.

### Aggregate cache

List pages cache their aggregation queries so a busy install isn't re-scanned on every poll:
`MONITOR_AGGREGATE_CACHE_LIVE_TTL` (defaults to the refresh interval) for a range ending now,
`MONITOR_AGGREGATE_CACHE_CLOSED_TTL` (3600 s) for a fixed past range. It uses a dedicated `file`
store by default (`MONITOR_AGGREGATE_CACHE_STORE`), isolated from your app's cache; set
`MONITOR_AGGREGATE_CACHE_ENABLED=false` to turn it off.

## Running code without recording it

```php
use LaravelMonitor\Facades\Monitor;

$value = Monitor::ignore(fn () => Cache::get('secret'));
```

`Monitor::stopRecording()` / `Monitor::startRecording()` do the same for a longer stretch of code.

## How it works

Recorders subscribe to framework events and buffer entries in memory — monitoring runs no queries
while your request is being handled. The buffer is written in one batch at the end of each request,
job attempt, command or scheduled task (or early, once it reaches `MONITOR_BUFFER` entries).
Recording is paused while flushing, so the monitor never records its own writes, and the
dashboard's own requests aren't recorded at all.

Everything lives in one `monitor_entries` table (`type` / `key` / `payload` columns) plus
`monitor_aggregates` for roll-ups. Reads go through narrow contracts in `LaravelMonitor\Contracts`
(`AggregateStorage`, `TimelineStorage`, `UserStorage`, `CacheAndQueryStorage`,
`ExceptionStorage`, `IssueStorage`, `HashResolver`) plus `EntryWriter` for writes, each bound to a
`Database*Storage` class in `MonitorServiceProvider::registerBindings()`. To use a different
backend, rebind those contracts in your own service provider.

## Testing

```bash
composer install
composer test
```

## License

MIT
