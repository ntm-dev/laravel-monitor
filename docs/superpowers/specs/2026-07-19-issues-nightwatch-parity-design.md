# Issues page: Nightwatch parity (ticket IDs, priority, dedicated detail page)

Date: 2026-07-19
Status: Approved (pending implementation plan)

## Motivation

The Issues tab (`/monitor/issues`) currently renders exceptions and
performance-threshold breaches as a simple divided list: no ticket number,
no priority, no dedicated detail route, and (as a separate bug already
fixed) exception rows were showing a fingerprint hash instead of the
exception class name. The user wants this tab restyled to match
[Laravel Nightwatch's own Issues → Exceptions view](https://nightwatch.laravel.com),
which presents a sortable, checkbox-selectable table with a numeric ticket
ID, a priority indicator, and a dedicated `/issues/{id}` detail page with a
"Manage" panel (Status + Priority).

Nightwatch also has an Assignee field and an AI-generated Description box.
Both are explicitly out of scope: this package has no team/user-account
system of its own (the dashboard is gated by a single `viewMonitor` Gate
against the host app's own Auth guard, not a multi-user team), and the AI
description generator is a Nightwatch-specific paid feature.

## Scope

In scope:
- `monitor_issues` gains a `uuid` (UUID v7) and a `priority` column.
- The Issues list (both Exceptions and Performance sub-views) becomes a
  table with an ID column, a read-only priority indicator, Count, Users
  (exceptions only), First seen, Last seen, and a link to the detail page.
- Row checkboxes + a bulk action bar (Resolve / Ignore) when ≥1 row is
  selected.
- A new `GET /monitor/issues/{uuid}` detail route:
  - Exception issues: today's `exception-detail` content (summary, chart,
    stack trace, occurrences) plus a new "Manage" sidebar panel
    (Status + Priority).
  - Performance issues: a compact Manage panel (Status + Priority + a
    label/count/max-duration summary) with a "View details" link back to
    the breaching area's own tab (Requests/Jobs/Queries/...), same as
    today's behavior.
- Sidebar "Issues" badge showing the open-issue count (already shipped in
  a prior change, unaffected by this spec).

Out of scope:
- Assignee (no team/user-account concept to assign to).
- Description/notes field.
- Interactive column sorting on the Issues table (Nightwatch supports it;
  v1 here keeps the existing fixed ordering — worst/most-recent first —
  to control scope. Can be added later without touching the data model).
- Inline priority editing from the list row (priority is set from the
  detail page's Manage panel only).

## Data model

New migration `<timestamp>_add_uuid_and_priority_to_monitor_issues_table.php`
(same connection-resolution pattern as the existing
`2024_01_02_000000_create_monitor_issues_table.php`, i.e.
`getConnection()` returns `config('monitor.storage.database.connection')`):

```php
Schema::table($this->issuesTable(), function (Blueprint $table) {
    $table->uuid('uuid')->nullable()->after('id');
    $table->string('priority', 16)->default('none')->after('status');
});

// Backfill existing rows before enforcing NOT NULL + unique, since
// `uuid()` has no default and existing rows would otherwise collide on
// the same empty value.
DB::connection($this->getConnection())->table($this->issuesTable())
    ->whereNull('uuid')
    ->orderBy('id')
    ->each(fn ($row) => DB::connection($this->getConnection())
        ->table($this->issuesTable())
        ->where('id', $row->id)
        ->update(['uuid' => (string) Str::uuid7()]));

Schema::table($this->issuesTable(), function (Blueprint $table) {
    $table->uuid('uuid')->nullable(false)->unique()->change();
});
```

- `id` (existing auto-increment PK) stays the primary key and is what's
  displayed as the ticket number (`#58`) in the list — unchanged.
- `uuid` is generated with `Str::uuid7()` (time-ordered) on insert (in
  `syncIssues()`/`setIssueStatus()`'s insert branches) and used only as
  the `/monitor/issues/{uuid}` route parameter, so sequential ticket
  counts aren't guessable from the URL.
- `priority` is a plain string column, one of
  `none|low|medium|high|urgent`, defaulting to `none`. No enum/lookup
  table — matches how `status` is already modeled on this table.

## Storage contract changes

`src/Contracts/Storage.php` / `src/Storage/DatabaseStorage.php`:

- `issueStatuses(string $type, array $keys): Collection` — extend the
  returned object to include `id`, `uuid`, and `priority` (currently only
  `status` and `first_seen`). This is the method the Issues list already
  calls to attach status per row, so no new query is needed to get IDs
  onto the list.
- `setIssuePriority(string $type, string $key, string $priority): void`
  — mirrors `setIssueStatus()`: validates against the 5 allowed values,
  creates the row if `syncIssues()` hasn't seen this key yet.
- `findIssueByUuid(string $uuid): ?object` — returns the issue row
  (`id, uuid, type, key, status, priority, first_seen, last_seen`) for
  the detail route to resolve `{uuid}` into a `type`/`key` pair before
  delegating to the existing per-type data-fetching logic
  (`exceptionGroups()` for exceptions, the same threshold-breach lookup
  `Issues::performanceIssues()` already does for performance).

## Components

### `Livewire\Issues` (list)

- `data()` already syncs issues and attaches status; extend
  `attachIssueStatus()` to also carry `id`, `uuid`, `priority` onto each
  row (from the extended `issueStatuses()`).
- Add `selected: array` public property (Livewire) for the checkbox
  selection state, and `resolveSelected()` / `ignoreSelected()` methods
  that loop the existing `setStatus()` over the selected `[type,key]`
  pairs, then clear `$selected`.
- `Str::limit`/`class_basename` bug fix already shipped separately
  (exception name now reads `$exception->latest['class']`, not the
  fingerprint key) — unaffected by this spec, just noted for context.

### `resources/views/livewire/issues.blade.php`

Both the Exceptions and Performance sub-views become a `<table>` (same
visual conventions as `exceptions.blade.php`'s table: font-mono uppercase
tracking-tight header row, divide-y body rows), columns:

`[checkbox] # | priority-icon | Issue | Count | Users | First seen | Last seen | [link]`

- Header checkbox toggles all visible rows into `$selected`.
- `Issue` cell: class/label in neutral text (`text-neutral-800
  dark:text-neutral-200`, no more `text-rose-*`) + message/sub-label
  below in muted gray, same truncation pattern already used elsewhere.
- `Users` cell: populated for exceptions (existing per-key user count
  logic); renders `—` for performance rows.
- Priority icon: new `Icons::PRIORITY` (Heroicons-style ascending
  signal-bars glyph), colored by level — `none`: neutral-300/600,
  `low`: blue-500, `medium`: amber-500, `high`: orange-500, `urgent`:
  rose-500. Read-only in the list (title attribute shows the label on
  hover); editing happens on the detail page.
- Row still links to `/monitor/issues/{uuid}` (replacing today's link to
  the Exceptions tab overview / the breaching area's own tab — that
  destination moves to the new detail page's "View details" link for
  performance issues).
- Bulk action bar: a slim bar above the table, shown only when
  `count($selected) > 0`, with a "N selected" label and Resolve/Ignore
  buttons (same button styling as the existing per-row actions).

### New: issue detail route + component

- Route: `GET /monitor/issues/{uuid}` → `monitor.issues.show`, registered
  alongside the existing `monitor.requests.show` / `monitor.jobs.attempts.show`
  pattern in `routes/`.
- New Livewire component `LaravelMonitor\Livewire\IssueDetail`:
  - `mount(string $uuid)` resolves the issue via
    `storage()->findIssueByUuid($uuid)`; 404s (or renders a "not found"
    card, matching `ExceptionDetail`'s existing `$exists` pattern) if
    missing.
  - For `type === 'exception'`: the frame-grouping, source-reading, and
    markdown-building methods currently private to `ExceptionDetail`
    (`frameGroups()`, `prepareFrame()`, `sourceLines()`, `readSource()`,
    `isVendor()`/`relativePath()`-adjacent helpers, `markdown()`, and the
    `summary()` row-builder) move into a new
    `LaravelMonitor\Livewire\Concerns\BuildsExceptionDetail` trait, used
    by both `ExceptionDetail` (unchanged behavior) and `IssueDetail`, so
    neither duplicates the other's stack-trace shaping logic.
    `IssueDetail` additionally exposes `id`, `status`, `priority` for the
    Manage panel.
  - For `type !== 'exception'` (performance): fetches the same summary
    a Performance-tab row already carries (`badge`, `label`, `tab`,
    `count`, `max_duration`, `last_seen`) plus `id`/`status`/`priority`,
    and the target URL for "View details".
  - `setStatus(string $status)` / `setPriority(string $priority)` Livewire
    actions call the corresponding storage methods and refresh.
- New view `resources/views/livewire/issue-detail.blade.php`:
  - Two-column layout (`grid lg:grid-cols-[1fr_260px]` or similar): main
    content on the left (existing exception detail content, or the
    compact performance summary), a "Manage" card on the right with:
    - `Status`: three buttons (Open/Resolved/Ignored), current one
      highlighted — same semantics as today's Resolve/Ignore/Reopen
      actions, just presented as a tri-state control instead of
      conditional buttons.
    - `Priority`: a `<select>` (five options), submits on change.
    - `#<id>` shown as a small label at the top of the panel or next to
      the page title.

## Testing plan

- Migration: a feature test asserting `uuid` is unique/non-null and
  `priority` defaults to `none` after running migrations against a fresh
  schema, and that existing rows created before the migration end up with
  a backfilled `uuid` (simulate by inserting a row via raw query before
  calling the migration's `up()` in isolation, or — simpler, matching
  this repo's existing test style — just assert the invariants hold after
  `RefreshDatabase` migrates everything from scratch, since there's no
  installed-base to test an upgrade path against in this package's own
  test suite).
- `DatabaseStorage`: unit/feature tests for `setIssuePriority()` (rejects
  invalid values, same pattern as the existing `setIssueStatus()` test),
  `findIssueByUuid()` (found / not-found), and that `issueStatuses()` now
  returns `id`/`uuid`/`priority`.
- `Livewire\Issues`: test that rows carry `id`/`uuid`/`priority`; test
  `resolveSelected()`/`ignoreSelected()` bulk-update multiple rows in one
  call and clear selection afterward.
- `Livewire\IssueDetail`: test resolving by uuid for both an exception and
  a performance issue, a 404-equivalent path for an unknown uuid, and that
  `setStatus()`/`setPriority()` persist.
- Blade syntax check (`php -l`) on both touched/new view files, as done
  throughout this session — this repo has no Livewire browser test
  tooling.
