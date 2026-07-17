# AGENTS.md

## What this is
Laravel package (`ntm-dev/laravel-monitor`): local-first, Nightwatch-style
monitoring dashboard (Livewire) for requests, queries, jobs, exceptions,
cache, mail, etc. Package root is this repo; source (`autoload: LaravelMonitor\`) lives in `src/`.

## Commands
```bash
composer install
composer test        # = phpunit; must stay green (currently 31 tests)
php -l path/to/file.blade.php   # syntax-check a single blade file
```

## Architecture
- `src/Recorders/*` — subscribe to framework events (RequestHandled, QueryExecuted, ...),
  buffer entries in memory via `Monitor::record()`. No queries during the request itself;
  buffer flushes once at request/job/command end.
- `src/Storage/DatabaseStorage.php` implements `Contracts/Storage` — single `monitor_entries` table,
  `type`/`key`/`payload` columns. `routeStats()` etc. aggregate via `groupBy('key')`.
- `src/Livewire/*` — one card class per dashboard tab (`Requests`, `Queries`, `JobDetail`, ...),
  paired with `resources/views/livewire/*.blade.php`.
- `resources/views/components/*` — shared Blade components, registered as the `x-monitor::`
  namespace (see `MonitorServiceProvider::boot()`), e.g. `<x-monitor::card>`.

## Conventions
- UI mirrors Laravel Nightwatch's dashboard style: dotted-line `dl` metric rows
  (see `resources/views/livewire/query-detail.blade.php`, `exception-detail.blade.php`),
  Tailwind CDN JIT, dark mode via `.dark` class.
- Route-list "key" format for `type: 'request'` entries is `"METHOD URI"` (e.g. `"GET /api/foo"`);
  list views split it back apart with `Str::before`/`Str::after`. Requests with no matched
  Laravel route are grouped under the literal key `Requests::UNMATCHED_ROUTE` ("Unmatched Route").

## Gotchas
- **`<pre><code>...</code></pre>` must have zero whitespace/newline between the tags.**
  `pre` preserves whitespace literally — any indentation before `<code>` renders as a leading
  blank line, pushing SQL/text output sideways. Bit us once in `timeline.blade.php`.
- `tests/TestCase.php::setUp()` explicitly flushes the Monitor buffer and purges storage —
  RefreshDatabase's own migration queries get captured by the Queries recorder otherwise,
  polluting every test with framework-bootstrap noise.

## Workflow
Only commit when explicitly asked — drafting a commit message is not permission to commit.
