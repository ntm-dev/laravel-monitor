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
- **All user-facing text must go through the translation system** — `__('monitor::messages.<section>.<key>')`,
  never a hardcoded string in a Blade view. Add the key to both
  `resources/lang/en/messages.php` and `resources/lang/vi/messages.php` (this package ships
  English + Vietnamese; keep them in sync). See the existing `nav`/`group`/`settings` sections
  for the nesting convention (one sub-array per feature/page).
- Route-list "key" format for `type: 'request'` entries is `"METHOD URI"` (e.g. `"GET /api/foo"`);
  list views split it back apart with `Str::before`/`Str::after`. Requests with no matched
  Laravel route are grouped under the literal key `Requests::UNMATCHED_ROUTE` ("Unmatched Route").

## Migrations

**Single migration file, always.** This package is installed into other
Laravel apps purely to monitor them, and the integration only allows one
migration file. All tables live in
`database/migrations/2026_01_01_000000_create_monitor_table.php` — do not
add a second migration file for any reason, including a new table.

When the schema needs to change:
1. Edit the relevant `Schema::create()`/`Schema::table()` block in that one
   file directly, so a fresh install still produces the right structure.
2. Apply the same change to any already-migrated database (this repo's own
   dev setup, a consuming app) with a manual `ALTER`/`Schema::table()` call
   run directly (e.g. via `artisan tinker`) — never by writing a new
   migration file to carry the change.

## GitHub issue automation

This repo has a slash-command pipeline for turning a GitHub issue directly into a PR:

- `/fix-issue <issue-number>` — bug reports (`bug` label). Branches `fix/issue-<n>-...`,
  dispatches to the `issue-bug-fixer` subagent (writes a failing regression test first, then
  fixes the root cause), runs the `security-review` skill on the diff, commits as `fix: ...` /
  `Fixes #<n>`, opens the PR.
- `/implement-issue <issue-number>` — feature requests (`enhancement` label). Branches
  `feat/issue-<n>-...`, dispatches to the `issue-feature-builder` subagent (tests first, then
  implements), runs the `security-review` skill on the diff, commits as `feat: ...` /
  `Closes #<n>`, opens the PR.
- `/work-issue <issue-number>` — reads the issue's labels/content and picks one of the two
  pipelines above automatically; use this when you haven't pre-classified the issue yourself.
- Issue forms live in `.github/ISSUE_TEMPLATE/` (`bug_report.yml`, `feature_request.yml`) and
  apply the `bug`/`enhancement` labels these commands key off of — keep the templates and this
  routing logic in sync if the label taxonomy changes.

Both subagents stop short of committing, pushing, or verifying UI changes in a browser — the
calling command handles git/PR steps, and a human is expected to do the actual browser check
for anything the subagent flags as unverified. Neither pipeline merges its own PR.

## Gotchas
- **`<pre><code>...</code></pre>` must have zero whitespace/newline between the tags.**
  `pre` preserves whitespace literally — any indentation before `<code>` renders as a leading
  blank line, pushing SQL/text output sideways. Bit us once in `timeline.blade.php`.
- `tests/TestCase.php::setUp()` explicitly flushes the Monitor buffer and purges storage —
  RefreshDatabase's own migration queries get captured by the Queries recorder otherwise,
  polluting every test with framework-bootstrap noise.

## Workflow
Only commit when explicitly asked — drafting a commit message is not permission to commit.
Running `/fix-issue`, `/implement-issue`, or `/work-issue` *is* that explicit ask for the scope
of that one issue — those commands are expected to commit and open a PR without a separate
confirmation step, per their own instructions in `.claude/commands/`.
