# AGENTS.md

## What this is
Laravel package (`ntm-dev/laravel-monitor`): local-first monitoring
dashboard (Livewire) for requests, queries, jobs, exceptions,
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
- **All user-facing text must go through the translation system** — `__('monitor::messages.<section>.<key>')`,
  never a hardcoded string in a Blade view. Add the key to both
  `resources/lang/en/messages.php` and `resources/lang/vi/messages.php` (this package ships
  English + Vietnamese; keep them in sync). See the existing `nav`/`group`/`settings` sections
  for the nesting convention (one sub-array per feature/page).
- Blade-specific UI/markup conventions (dotted-line `dl` rows, route-list key format, the
  start/end comment pair for new blocks): see `resources/views/CLAUDE.md`.

## Migrations

See `database/migrations/CLAUDE.md` — this package allows only a single migration file, and
that file explains how to change the schema without adding a second one.

## GitHub issue automation

This repo has a slash-command pipeline for turning a GitHub issue directly into a PR:
`/fix-issue`, `/implement-issue`, and `/work-issue` (auto-picks between the first two based on
the issue's labels/content) — see `.claude/commands/` for what each one does step by step.

- Issue forms live in `.github/ISSUE_TEMPLATE/` (`bug_report.yml`, `feature_request.yml`) and
  apply the `bug`/`enhancement` labels these commands key off of — keep the templates and this
  routing logic in sync if the label taxonomy changes.

Both subagents stop short of committing, pushing, or verifying UI changes in a browser — the
calling command handles git/PR steps, and a human is expected to do the actual browser check
for anything the subagent flags as unverified. Neither pipeline merges its own PR.

## Gotchas
- Blade-rendering gotcha (`<pre><code>` whitespace): see `resources/views/CLAUDE.md`.
- Test-setup gotcha (`TestCase::setUp()` buffer flush): see `tests/CLAUDE.md`.

## Workflow
Only commit when explicitly asked — drafting a commit message is not permission to commit.
Running `/fix-issue`, `/implement-issue`, or `/work-issue` *is* that explicit ask for the scope
of that one issue — those commands are expected to commit and open a PR without a separate
confirmation step, per their own instructions in `.claude/commands/`.

**Branch naming**: the segment after `fix/`/`feat/` should be the singular name of the
dashboard route/tab the change touches — e.g. `fix/request` for a Requests-area bug,
`fix/exception` for Exceptions, `feat/job` for a Jobs-area feature. Tabs: request, job,
command, query, exception, notification, mail, application, cache, outgoing, setting, issue.
For multi-area or non-tab-specific work, fall back to a short kebab-case description as before
(e.g. `fix/dashboard-chart-tooltip-cross-hover`). This doesn't apply to the `/fix-issue` /
`/implement-issue` pipeline's own `fix|feat/issue-<n>-<slug>` naming, which prioritizes the
issue number for traceability.
