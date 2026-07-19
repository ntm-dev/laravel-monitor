# Issues page Nightwatch parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Issues tab a Nightwatch-style ticket number, a manual priority field, and a dedicated `/monitor/issues/{uuid}` detail page with a Status/Priority "Manage" panel, replacing today's card-list with a sortable-looking table.

**Architecture:** `monitor_issues` gains `uuid` (UUID v7, generated with `ramsey/uuid` — `Str::uuid7()` isn't available before Laravel 11) and `priority` columns. The list Livewire component (`Livewire\Issues`) attaches these to each row and gains checkbox multi-select with bulk Resolve/Ignore. A new plain (non-Livewire) `IssueController` — same "owns its own route, fetches everything itself" pattern as `RequestDetailController`/`JobAttemptController` — resolves `{uuid}` and renders either the exception's full detail (stack trace, occurrences, summary — reusing trace-shaping logic extracted from `ExceptionDetail` into a shared trait) or a compact performance-issue summary, plus a Manage panel that submits Status/Priority via plain POST-and-redirect (same convention as `SettingsController`).

**Tech Stack:** Laravel 10–13 (this package's supported range), Livewire 3/4, Blade + Tailwind (CDN, no build step), PHPUnit 10+, `ramsey/uuid` (transitive dependency of `laravel/framework`, always present).

## Global Constraints

- Support Laravel 10 through 13 (composer.json: `"illuminate/*": "^10.0|^11.0|^12.0|^13.0"`) — do not use `Str::uuid7()` (Laravel 11+ only); use `\Ramsey\Uuid\Uuid::uuid7()->toString()` instead, which is available on every supported version via `laravel/framework`'s own `ramsey/uuid: ^4.7` dependency.
- Do not use `Blueprint::change()` in migrations — it requires `doctrine/dbal`, which is not a guaranteed dependency on Laravel 10. Add new columns nullable instead of altering existing ones to NOT NULL.
- Follow `php-conventions`, `laravel-conventions`, `livewire-conventions`, and `phpunit-conventions` skills already available in this repo (control-structure style, migration `getConnection()` pattern, Livewire component conventions, running one test before the full suite).
- `AGENTS.md`: `<pre><code>` must have zero whitespace between the tags; only commit when explicitly asked (already asked for in this case — commit after each task).
- **This machine's Herd PHP CLI is broken** (missing dylib) — run all PHP/Composer/PHPUnit commands with `/opt/homebrew/bin/php` explicitly, e.g. `/opt/homebrew/bin/php vendor/bin/phpunit ...`, not bare `php`.
- After all tasks: run the full suite (`/opt/homebrew/bin/php vendor/bin/phpunit`), commit, push to `feat/nightwatch-parity-improvements`, update the PR title/description, and watch CI — fix and re-push if anything fails (per explicit user instruction for this session).

---

### Task 1: `monitor_issues` gains `uuid` and `priority` columns

**Files:**
- Create: `database/migrations/2026_07_19_000000_add_uuid_and_priority_to_monitor_issues_table.php`
- Test: `tests/IssuesTest.php` (add to existing file)

**Interfaces:**
- Produces: `monitor_issues.uuid` (nullable `uuid`, unique index `monitor_issues_uuid_unique`, backfilled for any pre-existing rows), `monitor_issues.priority` (string(16), default `'none'`).

- [ ] **Step 1: Write the failing test**

Add to `tests/IssuesTest.php` (new imports go at the top alongside the existing `use` statements):

```php
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
```

```php
public function test_monitor_issues_has_uuid_and_priority_columns_with_defaults(): void
{
    $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_issues', ['uuid', 'priority']));

    DB::table('monitor_issues')->insert([
        'type' => 'exception',
        'key' => 'test-key',
        'status' => 'open',
        'uuid' => Uuid::uuid7()->toString(),
        'first_seen' => now(),
        'last_seen' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('monitor_issues')->where('key', 'test-key')->first();

    $this->assertSame('none', $row->priority);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_issues_has_uuid_and_priority_columns_with_defaults`
Expected: FAIL — `Schema::hasColumns` returns false (columns don't exist yet).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function getConnection()
    {
        return config('monitor.storage.database.connection');
    }

    public function up(): void
    {
        // `uuid` isn't enforced NOT NULL at the schema level — that would
        // need Blueprint::change(), which requires doctrine/dbal, not a
        // guaranteed dependency on Laravel 10. Every insert path
        // (Jobs/Exceptions recorders go through DatabaseStorage::syncIssues()
        // and setIssueStatus()/setIssuePriority()) always supplies one, and
        // this migration backfills existing rows below, so it's never
        // actually null in practice.
        Schema::table($this->issuesTable(), function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('priority', 16)->default('none')->after('status');
        });

        DB::connection($this->getConnection())
            ->table($this->issuesTable())
            ->whereNull('uuid')
            ->orderBy('id')
            ->get(['id'])
            ->each(fn ($row) => DB::connection($this->getConnection())
                ->table($this->issuesTable())
                ->where('id', $row->id)
                ->update(['uuid' => Uuid::uuid7()->toString()]));
    }

    public function down(): void
    {
        Schema::table($this->issuesTable(), function (Blueprint $table) {
            $table->dropUnique([$this->issuesTable().'_uuid_unique']);
            $table->dropColumn(['uuid', 'priority']);
        });
    }

    protected function issuesTable(): string
    {
        return config('monitor.issues.table', 'monitor_issues');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_monitor_issues_has_uuid_and_priority_columns_with_defaults`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_19_000000_add_uuid_and_priority_to_monitor_issues_table.php tests/IssuesTest.php
git commit -m "feat: add uuid and priority columns to monitor_issues"
```

---

### Task 2: `Format::PRIORITIES` + `Format::priorityLabel()`

**Files:**
- Modify: `src/Support/Format.php`
- Test: `tests/MonitorTest.php` (add near other `Format`-adjacent assertions, or as new standalone test methods anywhere in the class)

**Interfaces:**
- Produces: `Format::PRIORITIES` (`const array<string,string>`, keys `none|low|medium|high|urgent`), `Format::priorityLabel(string $priority): string`.

- [ ] **Step 1: Write the failing test**

Add to `tests/MonitorTest.php`:

```php
public function test_format_priority_label_returns_the_human_label(): void
{
    $this->assertSame('No priority', \LaravelMonitor\Support\Format::priorityLabel('none'));
    $this->assertSame('Urgent', \LaravelMonitor\Support\Format::priorityLabel('urgent'));
}

public function test_format_priority_label_falls_back_to_no_priority_for_an_unknown_value(): void
{
    $this->assertSame('No priority', \LaravelMonitor\Support\Format::priorityLabel('made-up'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_format_priority_label`
Expected: FAIL — `Format::priorityLabel()` doesn't exist.

- [ ] **Step 3: Add the constant and method**

In `src/Support/Format.php`, add inside the `Format` class (after the existing `RANGE` constant):

```php
    /**
     * Manual issue-priority levels, value => human label — mirrors
     * Nightwatch's five-level priority field on an Issue.
     */
    public const PRIORITIES = [
        'none' => 'No priority',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];
```

And add this method (after `timezone()`):

```php
    public static function priorityLabel(string $priority): string
    {
        return self::PRIORITIES[$priority] ?? self::PRIORITIES['none'];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_format_priority_label`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Support/Format.php tests/MonitorTest.php
git commit -m "feat: add Format::PRIORITIES and priorityLabel()"
```

---

### Task 3: Storage — `findIssueByUuid()`, `setIssuePriority()`, extend `issueStatuses()`, generate `uuid` on insert

**Files:**
- Modify: `src/Contracts/Storage.php`
- Modify: `src/Storage/DatabaseStorage.php`
- Modify: `src/Livewire/Issues.php:126` (the one caller of `issueStatuses()`, to consume the new fields — done in Task 5, not here; this task only changes the storage layer and adds tests against it directly)
- Test: `tests/IssuesTest.php`

**Interfaces:**
- Consumes: `Format::PRIORITIES` (Task 2).
- Produces:
  - `Storage::findIssueByUuid(string $uuid): ?object` — returns `(object) ['id' => int, 'uuid' => string, 'type' => string, 'key' => string, 'status' => string, 'priority' => string, 'first_seen' => CarbonImmutable, 'last_seen' => CarbonImmutable]` or `null`.
  - `Storage::setIssuePriority(string $type, string $key, string $priority): void`.
  - `Storage::issueStatuses(string $type, array $keys): Collection` now returns objects shaped `{id: int, uuid: string, status: string, priority: string, first_seen: CarbonImmutable}` (was `{status, first_seen}`).
  - `syncIssues()` and `setIssueStatus()`'s insert branches now populate `uuid` on every newly-created row.

- [ ] **Step 1: Write the failing tests**

Add to `tests/IssuesTest.php`:

```php
public function test_set_issue_priority_persists_and_creates_the_row_if_missing(): void
{
    $storage = app(\LaravelMonitor\Contracts\Storage::class);

    $storage->setIssuePriority('exception', 'App\\Exceptions\\Boom', 'high');

    $statuses = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom']);

    $this->assertSame('high', $statuses->get('App\\Exceptions\\Boom')->priority);
    $this->assertNotNull($statuses->get('App\\Exceptions\\Boom')->uuid);
}

public function test_set_issue_priority_rejects_an_invalid_value(): void
{
    $storage = app(\LaravelMonitor\Contracts\Storage::class);

    Monitor::record('exception', 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
    Monitor::flush();

    Livewire::test(Issues::class)->set('view', 'exceptions'); // triggers syncIssues() for the row above

    $storage->setIssuePriority('exception', 'App\\Exceptions\\Boom', 'not-a-real-priority');

    $this->assertSame('none', $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->priority);
}

public function test_find_issue_by_uuid_returns_the_matching_row(): void
{
    $storage = app(\LaravelMonitor\Contracts\Storage::class);
    $storage->setIssueStatus('exception', 'App\\Exceptions\\Boom', 'open');

    $uuid = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->uuid;

    $found = $storage->findIssueByUuid($uuid);

    $this->assertNotNull($found);
    $this->assertSame('exception', $found->type);
    $this->assertSame('App\\Exceptions\\Boom', $found->key);
}

public function test_find_issue_by_uuid_returns_null_for_an_unknown_uuid(): void
{
    $storage = app(\LaravelMonitor\Contracts\Storage::class);

    $this->assertNull($storage->findIssueByUuid((string) \Illuminate\Support\Str::uuid()));
}

public function test_sync_issues_assigns_a_uuid_to_newly_created_rows(): void
{
    $storage = app(\LaravelMonitor\Contracts\Storage::class);

    $storage->syncIssues('exception', ['App\\Exceptions\\Boom' => now()]);

    $status = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom');

    $this->assertNotNull($status->uuid);
    $this->assertSame(36, strlen($status->uuid));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=IssuesTest`
Expected: FAIL — `setIssuePriority`/`findIssueByUuid` undefined, and `issueStatuses()` results don't carry `priority`/`uuid` yet.

- [ ] **Step 3: Extend the `Storage` contract**

In `src/Contracts/Storage.php`, replace the `issueStatuses()` docblock (it's unchanged in signature, only its return shape's documentation needs updating) and add the two new method declarations after `setIssueStatus()`:

```php
    /**
     * Status + priority + first_seen for each of the given keys of a type,
     * keyed by key — batches what would otherwise be one lookup per row on
     * the Issues page. A key with no matching row (not yet synced) is
     * simply absent.
     *
     * @param  string[]  $keys
     * @return Collection<string, object{id: int, uuid: string, status: string, priority: string, first_seen: CarbonImmutable}>
     */
    public function issueStatuses(string $type, array $keys): Collection;

    /**
     * Set an issue's status directly (open/resolved/ignored) — the resolve/
     * ignore/reopen actions on the Issues page. Creates the row if
     * syncIssues() hasn't seen this key yet rather than silently no-op-ing.
     */
    public function setIssueStatus(string $type, string $key, string $status): void;

    /**
     * Count of issues currently "open" — powers the sidebar badge. Not
     * scoped to the viewer's selected time range: issues are persistent
     * records synced by syncIssues(), not a windowed event count.
     */
    public function openIssueCount(): int;

    /**
     * Set an issue's priority (one of Format::PRIORITIES' keys) — silently
     * no-ops on an invalid value. Creates the row if syncIssues() hasn't
     * seen this key yet, same as setIssueStatus().
     */
    public function setIssuePriority(string $type, string $key, string $priority): void;

    /**
     * Resolve a monitor_issues row by its uuid — the /monitor/issues/{uuid}
     * detail route uses this to find the [type, key] pair to fetch the
     * underlying exception/performance data for.
     */
    public function findIssueByUuid(string $uuid): ?object;
}
```

(This replaces from the existing `issueStatuses()` declaration through the closing `}` of the interface — `setIssueStatus()` and `openIssueCount()` are unchanged, just copied here for placement context.)

- [ ] **Step 4: Update `DatabaseStorage`**

In `src/Storage/DatabaseStorage.php`, add these two imports at the top (alongside the existing `use` block):

```php
use Illuminate\Support\Str;
use LaravelMonitor\Support\Format;
use Ramsey\Uuid\Uuid;
```

Replace the `issueStatuses()` method body:

```php
    public function issueStatuses(string $type, array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        return $this->issuesTable()
            ->where('type', $type)
            ->whereIn('key', $keys)
            ->get(['id', 'uuid', 'key', 'status', 'priority', 'first_seen'])
            ->keyBy('key')
            ->map(fn ($row) => (object) [
                'id' => (int) $row->id,
                'uuid' => $row->uuid,
                'status' => $row->status,
                'priority' => $row->priority,
                'first_seen' => CarbonImmutable::parse($row->first_seen),
            ]);
    }
```

In `syncIssues()`, the insert branch currently reads:

```php
            if ($row === null) {
                $this->issuesTable()->insert([
                    'type' => $type,
                    'key' => $key,
                    'status' => 'open',
                    'first_seen' => $lastSeenValue,
                    'last_seen' => $lastSeenValue,
                    'resolved_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }
```

Add a `'uuid' => Uuid::uuid7()->toString(),` entry to that insert array (any position — matches the existing key ordering style by placing it right after `'key' => $key,`):

```php
            if ($row === null) {
                $this->issuesTable()->insert([
                    'type' => $type,
                    'key' => $key,
                    'uuid' => Uuid::uuid7()->toString(),
                    'status' => 'open',
                    'first_seen' => $lastSeenValue,
                    'last_seen' => $lastSeenValue,
                    'resolved_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }
```

In `setIssueStatus()`, the insert branch currently reads:

```php
        if (! $exists) {
            // An action performed on an issue syncIssues() hasn't recorded
            // yet (edge case) — insert a fresh row rather than no-op.
            $this->issuesTable()->insert([
                'type' => $type,
                'key' => $key,
                'status' => $status,
                'first_seen' => $now,
                'last_seen' => $now,
                'resolved_at' => $status === 'resolved' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }
```

Add `'uuid' => Uuid::uuid7()->toString(),` there too:

```php
        if (! $exists) {
            // An action performed on an issue syncIssues() hasn't recorded
            // yet (edge case) — insert a fresh row rather than no-op.
            $this->issuesTable()->insert([
                'type' => $type,
                'key' => $key,
                'uuid' => Uuid::uuid7()->toString(),
                'status' => $status,
                'first_seen' => $now,
                'last_seen' => $now,
                'resolved_at' => $status === 'resolved' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }
```

Immediately after the `setIssueStatus()` method (and before `protected function bucketGrid()`), add the two new methods:

```php
    public function setIssuePriority(string $type, string $key, string $priority): void
    {
        if (! array_key_exists($priority, Format::PRIORITIES)) {
            return;
        }

        $now = CarbonImmutable::now();
        $exists = $this->issuesTable()->where('type', $type)->where('key', $key)->exists();

        if (! $exists) {
            $this->issuesTable()->insert([
                'type' => $type,
                'key' => $key,
                'uuid' => Uuid::uuid7()->toString(),
                'status' => 'open',
                'priority' => $priority,
                'first_seen' => $now,
                'last_seen' => $now,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->issuesTable()->where('type', $type)->where('key', $key)->update([
            'priority' => $priority,
            'updated_at' => $now,
        ]);
    }

    public function findIssueByUuid(string $uuid): ?object
    {
        $row = $this->issuesTable()->where('uuid', $uuid)->first();

        if ($row === null) {
            return null;
        }

        return (object) [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'type' => $row->type,
            'key' => $row->key,
            'status' => $row->status,
            'priority' => $row->priority,
            'first_seen' => CarbonImmutable::parse($row->first_seen),
            'last_seen' => CarbonImmutable::parse($row->last_seen),
        ];
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=IssuesTest`
Expected: PASS (all `IssuesTest` methods, including the new ones and the pre-existing ones from before this plan)

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS — `Str` unused-import check aside, nothing else references the old `issueStatuses()` shape except `Livewire\Issues::attachIssueStatus()`, which only reads `->status` and `->first_seen` today (Task 5 will read the new fields too), so it keeps working unchanged.

- [ ] **Step 7: Commit**

```bash
git add src/Contracts/Storage.php src/Storage/DatabaseStorage.php tests/IssuesTest.php
git commit -m "feat: add issue priority storage and uuid lookup"
```

---

### Task 4: Extract stack-trace/markdown/summary building into a shared trait

**Files:**
- Create: `src/Livewire/Concerns/BuildsExceptionDetail.php`
- Modify: `src/Livewire/ExceptionDetail.php`
- Test: none new — this is a pure refactor; the existing `test_exception_detail_page_renders` test in `tests/MonitorTest.php:558` and the exception-group tests already cover this code path.

**Interfaces:**
- Produces: trait `LaravelMonitor\Livewire\Concerns\BuildsExceptionDetail` with protected methods `frameGroups(array $frames): array`, `prepareFrame(array $frame, bool $vendor, bool $main): array`, `sourceLines(?string $code, int $start, int $errorLine): array`, `readSource(string $relative, int $line): array`, `markdown(array $payload, bool $handled): string`, `summary(?object $lastSeen, ?object $firstSeen, ?string $phpVersion, ?string $laravelVersion, int $impactedUsers, int $occurrencesCount, Collection $servers, string $tz): array`. Any class that `use`s this trait gets these methods — no constructor/property dependencies, so it's safe to mix into both `ExceptionDetail` (a `Livewire\Card`) and, in Task 6, a plain controller.

- [ ] **Step 1: Create the trait**

`src/Livewire/Concerns/BuildsExceptionDetail.php` — this is an exact move of `ExceptionDetail`'s `summary()`, `frameGroups()`, `prepareFrame()`, `sourceLines()`, `readSource()`, and `markdown()` methods (currently at `src/Livewire/ExceptionDetail.php:89-265`):

```php
<?php

namespace LaravelMonitor\Livewire\Concerns;

use Illuminate\Support\Collection;

/**
 * Shape a fetched exception's raw payload (frames, message, class, ...)
 * into what `exception-detail.blade.php` renders: grouped/numbered stack
 * frames with their source snippets, the "Copy as Markdown" text, and the
 * labelled Summary rows. Pure data transformation — no storage/HTTP
 * dependency — so it's shared by both the tab-based `ExceptionDetail`
 * Livewire card and the standalone `/monitor/issues/{uuid}` page
 * (`Http\Controllers\IssueController`), which fetch the underlying data
 * differently (period-scoped vs. all-time) but shape it identically.
 */
trait BuildsExceptionDetail
{
    /**
     * Build the labelled Summary rows shown in the metadata card.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function summary(
        ?object $lastSeen,
        ?object $firstSeen,
        ?string $phpVersion,
        ?string $laravelVersion,
        int $impactedUsers,
        int $occurrencesCount,
        Collection $servers,
        string $tz,
    ): array {
        return [
            ['Last Seen', $lastSeen ? \LaravelMonitor\Support\Format::datetime($lastSeen).' '.$tz : '—'],
            ['First Seen', $firstSeen ? \LaravelMonitor\Support\Format::datetime($firstSeen).' '.$tz : '—'],
            ['First Reported In', $laravelVersion ? 'Laravel '.$laravelVersion : '—'],
            ['PHP Version', $phpVersion ?? '—'],
            ['Laravel Version', $laravelVersion ?? '—'],
            ['Impacted Users', number_format($impactedUsers)],
            ['Occurrences', number_format($occurrencesCount)],
            ['Servers', $servers->isNotEmpty() ? $servers->implode(', ') : '—'],
        ];
    }

    /**
     * Group frames the way the trace view renders them: consecutive vendor
     * frames collapse into one block, and each frame carries its ready-to-print
     * source lines so the Blade view stays free of logic.
     *
     * @param  array<int, array<string, mixed>>  $frames
     * @return array<int, array{vendor: bool, count: int, frames: array<int, array<string, mixed>>}>
     */
    protected function frameGroups(array $frames): array
    {
        $defaultChosen = false;
        $groups = [];

        foreach ($frames as $frame) {
            $vendor = (bool) ($frame['vendor'] ?? false);

            // Open the first application frame by default, like Ignition.
            $main = ! $defaultChosen && ! $vendor;
            $defaultChosen = $defaultChosen || $main;

            $prepared = $this->prepareFrame($frame, $vendor, $main);
            $last = $groups[count($groups) - 1] ?? null;

            if ($vendor && $last && $last['vendor']) {
                $groups[count($groups) - 1]['frames'][] = $prepared;
                $groups[count($groups) - 1]['count']++;
            } else {
                $groups[] = ['vendor' => $vendor, 'count' => 1, 'frames' => [$prepared]];
            }
        }

        return $groups;
    }

    /**
     * Normalize a single frame and attach its numbered source lines (from the
     * stored snippet, or read from disk for real application frames).
     *
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>
     */
    protected function prepareFrame(array $frame, bool $vendor, bool $main): array
    {
        $line = (int) ($frame['line'] ?? 0);
        $code = $frame['code'] ?? null;
        $start = $frame['start_line'] ?? 1;

        if (empty($code)) {
            [$code, $start] = $this->readSource($frame['file'] ?? '', $line);
        }

        $lines = $this->sourceLines($code, (int) $start, $line);

        return [
            'label' => $frame['label'] ?? $frame['function'] ?? '{main}',
            'file' => $frame['file'] ?? '[internal]',
            'line' => $line,
            'vendor' => $vendor,
            'main' => $main,
            'has_code' => $lines !== [],
            'lines' => $lines,
        ];
    }

    /**
     * Turn a source snippet into numbered lines flagged with the error line.
     *
     * @return array<int, array{number: int, code: string, error: bool}>
     */
    protected function sourceLines(?string $code, int $start, int $errorLine): array
    {
        if ($code === null || $code === '') {
            return [];
        }

        $lines = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $code)) as $index => $text) {
            $number = $start + $index;
            $lines[] = ['number' => $number, 'code' => $text, 'error' => $number === $errorLine];
        }

        return $lines;
    }

    /**
     * Read a window of source lines around $line from a project-relative path,
     * when the file is actually present on disk.
     *
     * @return array{0: string|null, 1: int|null}
     */
    protected function readSource(string $relative, int $line): array
    {
        if ($relative === '' || $line < 1 || str_contains($relative, '[internal]')) {
            return [null, null];
        }

        $path = base_path($relative);

        if (! is_file($path) || ! is_readable($path)) {
            return [null, null];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [null, null];
        }

        $start = max(1, $line - 5);
        $end = min(count($lines), $line + 5);

        return [implode("\n", array_slice($lines, $start - 1, $end - $start + 1)), $start];
    }

    protected function markdown(array $payload, bool $handled): string
    {
        $lines = [
            '## '.($payload['class'] ?? 'Exception'),
            '',
            '- **Status:** '.($handled ? 'Handled' : 'Unhandled'),
        ];

        if (! empty($payload['message'])) {
            $lines[] = '- **Message:** '.$payload['message'];
        }

        if (! empty($payload['file'])) {
            $lines[] = '- **Location:** `'.$payload['file'].':'.($payload['line'] ?? 0).'`';
        }

        if (! empty($payload['php_version'])) {
            $lines[] = '- **PHP:** '.$payload['php_version'];
        }

        if (! empty($payload['laravel_version'])) {
            $lines[] = '- **Laravel:** '.$payload['laravel_version'];
        }

        $frames = $payload['frames'] ?? [];

        if ($frames !== []) {
            $lines[] = '';
            $lines[] = '### Stack trace';
            $lines[] = '```';

            foreach ($frames as $frame) {
                $lines[] = ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0).'  '.($frame['label'] ?? $frame['function'] ?? '');
            }

            $lines[] = '```';
        }

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 2: Update `ExceptionDetail` to use the trait**

In `src/Livewire/ExceptionDetail.php`, add the trait use statement and remove the six methods now living in the trait. The class becomes:

```php
<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\BuildsExceptionDetail;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\Format;

class ExceptionDetail extends Card
{
    use BuildsExceptionDetail;
    use ResolvesUserNames;

    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.exception-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;
        $key = $this->key;

        $group = $storage->exceptionGroups($since, $until)->firstWhere('key', $key);
        $occurrences = $storage->recent('exception', $since, 200, null, $key, $until);
        $latest = $occurrences->first();
        $payload = $latest->payload ?? [];

        $names = $this->resolveNames(
            $occurrences->pluck('user_id')->filter(fn ($id) => $id !== null)->unique()->all()
        );

        $servers = $occurrences->pluck('payload.server')->filter()->unique()->values();
        $handled = ($group?->unhandled ?? 0) === 0;
        $tz = Format::timezone();

        $lastSeen = $group?->last_seen ?? $latest?->created_at;
        $firstSeen = $storage->firstSeen('exception', $key) ?? $group?->first_seen;
        $phpVersion = $payload['php_version'] ?? null;
        $laravelVersion = $payload['laravel_version'] ?? null;
        $occurrencesCount = $group?->count ?? $storage->stats('exception', $since, null, $key, $until)->count;

        return [
            'key' => $key,
            'exists' => $latest !== null,
            'class' => $payload['class'] ?? $key,
            'message' => $payload['message'] ?? null,
            'file' => $payload['file'] ?? null,
            'line' => $payload['line'] ?? null,
            'handled' => $handled,
            'tz' => $tz,
            'phpVersion' => $phpVersion,
            'laravelVersion' => $laravelVersion,
            'frameGroups' => $this->frameGroups($payload['frames'] ?? []),
            'markdown' => $this->markdown($payload, $handled),
            'summary' => $this->summary($lastSeen, $firstSeen, $phpVersion, $laravelVersion, (int) ($group?->users ?? 0), $occurrencesCount, $servers, $tz),
            'occurrencesCount' => $occurrencesCount,
            'handledCount' => $group?->handled ?? 0,
            'unhandledCount' => $group?->unhandled ?? 0,
            // Timeline for this exception
            'handledBuckets' => $storage->countsPerBucket('exception', $since, $buckets, 'handled', $key, $until),
            'unhandledBuckets' => $storage->countsPerBucket('exception', $since, $buckets, 'unhandled', $key, $until),
            // Occurrences table
            'occurrences' => $occurrences->take(50)->map(fn ($row) => (object) [
                'date' => Format::datetime($row->created_at),
                'server' => $row->payload['server'] ?? null,
                'message' => $row->payload['message'] ?? null,
                'user' => $row->user_id !== null ? ($names[$row->user_id] ?? "User #{$row->user_id}") : null,
            ]),
        ];
    }
}
```

- [ ] **Step 3: Run the exception-detail regression test**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_exception_detail_page_renders`
Expected: PASS — same output as before the refactor, since the trait's method bodies are byte-for-byte the same code, just relocated.

- [ ] **Step 4: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Concerns/BuildsExceptionDetail.php src/Livewire/ExceptionDetail.php
git commit -m "refactor: extract exception detail shaping into a shared trait"
```

---

### Task 5: `Livewire\Issues` — attach id/uuid/priority, bulk selection

**Files:**
- Modify: `src/Livewire/Issues.php`
- Test: `tests/IssuesTest.php`

**Interfaces:**
- Consumes: `Storage::issueStatuses()` (Task 3, now returns `id`/`uuid`/`priority`).
- Produces: every row in `data()`'s `exceptions`/`performance` collections now carries `->id`, `->uuid`, `->priority` (in addition to the existing `->status`, `->first_seen`, `->issue_type`). New public property `$selected` (`array<string, array<string, true>>`, keyed `[type][key]`). New public methods `toggleSelected(string $type, string $key): void`, `selectAll(array $pairs): void`, `deselectAll(): void`, `selectedCount(): int`, `resolveSelected(): void`, `ignoreSelected(): void`. `PERFORMANCE_AREAS` becomes `public const` (Task 6's `IssueController` reads it directly).

- [ ] **Step 1: Write the failing tests**

Add to `tests/IssuesTest.php`:

```php
public function test_exception_rows_carry_id_uuid_and_priority(): void
{
    Monitor::record('exception', 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
    Monitor::flush();

    $row = Livewire::test(Issues::class)->set('view', 'exceptions')->viewData('exceptions')->first();

    $this->assertNotNull($row->id);
    $this->assertSame(36, strlen($row->uuid));
    $this->assertSame('none', $row->priority);
}

public function test_bulk_resolving_selected_exceptions_moves_them_out_of_the_open_tab(): void
{
    Monitor::record('exception', 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
    Monitor::record('exception', 'App\\Exceptions\\Bang', ['class' => 'App\\Exceptions\\Bang', 'message' => 'bang'], null, 'unhandled');
    Monitor::flush();

    $component = Livewire::test(Issues::class)->set('view', 'exceptions');
    $this->assertCount(2, $component->viewData('exceptions'));

    $component->call('toggleSelected', 'exception', 'App\\Exceptions\\Boom');
    $component->call('toggleSelected', 'exception', 'App\\Exceptions\\Bang');
    $this->assertSame(2, $component->instance()->selectedCount());

    $component->call('resolveSelected');

    $this->assertCount(0, $component->viewData('exceptions'));
    $this->assertSame(0, $component->instance()->selectedCount());

    $resolved = $component->set('status', 'resolved')->viewData('exceptions');
    $this->assertCount(2, $resolved);
}

public function test_toggling_the_same_row_twice_deselects_it(): void
{
    $component = Livewire::test(Issues::class)->set('view', 'exceptions');

    $component->call('toggleSelected', 'exception', 'App\\Exceptions\\Boom');
    $this->assertSame(1, $component->instance()->selectedCount());

    $component->call('toggleSelected', 'exception', 'App\\Exceptions\\Boom');
    $this->assertSame(0, $component->instance()->selectedCount());
}

public function test_select_all_selects_every_given_pair_and_switching_view_clears_selection(): void
{
    $component = Livewire::test(Issues::class)->set('view', 'exceptions');

    $component->call('selectAll', [['exception', 'App\\Exceptions\\Boom'], ['exception', 'App\\Exceptions\\Bang']]);
    $this->assertSame(2, $component->instance()->selectedCount());

    $component->set('view', 'performance');
    $this->assertSame(0, $component->instance()->selectedCount());
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=IssuesTest`
Expected: FAIL — `toggleSelected`/`selectAll`/`resolveSelected`/`selectedCount` undefined, rows don't carry `id`/`uuid`/`priority`.

- [ ] **Step 3: Update `src/Livewire/Issues.php`**

Change `protected const PERFORMANCE_AREAS` to `public const PERFORMANCE_AREAS` (same array contents, just the visibility keyword):

```php
    public const PERFORMANCE_AREAS = [
        'request' => ['badge' => 'Request', 'tab' => 'requests', 'threshold' => 'request'],
        'job' => ['badge' => 'Job', 'tab' => 'jobs', 'threshold' => 'job'],
        'slow_query' => ['badge' => 'Query', 'tab' => 'queries', 'threshold' => 'query'],
        'outgoing_request' => ['badge' => 'Outgoing', 'tab' => 'outgoing', 'threshold' => 'outgoing_request'],
        'command' => ['badge' => 'Command', 'tab' => 'commands', 'threshold' => 'command'],
    ];
```

Add `public array $selected = [];` right after `public string $search = '';`.

Add these public methods right after `reopen()` (before `protected function setStatus()`):

```php
    /**
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    public function selectAll(array $pairs): void
    {
        foreach ($pairs as [$type, $key]) {
            $this->selected[$type][$key] = true;
        }
    }

    public function deselectAll(): void
    {
        $this->selected = [];
    }

    public function toggleSelected(string $type, string $key): void
    {
        if (isset($this->selected[$type][$key])) {
            unset($this->selected[$type][$key]);

            if ($this->selected[$type] === []) {
                unset($this->selected[$type]);
            }

            return;
        }

        $this->selected[$type][$key] = true;
    }

    public function selectedCount(): int
    {
        return array_sum(array_map('count', $this->selected));
    }

    public function resolveSelected(): void
    {
        $this->applyStatusToSelected('resolved');
    }

    public function ignoreSelected(): void
    {
        $this->applyStatusToSelected('ignored');
    }

    /**
     * Livewire lifecycle hook — fires automatically whenever the public
     * $view property changes (the Exceptions/Performance toggle), since a
     * selection made while looking at one sub-tab shouldn't silently apply
     * to rows the viewer can no longer see.
     */
    public function updatedView(): void
    {
        $this->selected = [];
    }

    protected function applyStatusToSelected(string $status): void
    {
        foreach ($this->selected as $type => $keys) {
            foreach (array_keys($keys) as $key) {
                $this->setStatus($type, $key, $status);
            }
        }

        $this->selected = [];
    }
```

Update `attachIssueStatus()` to also attach `id`, `uuid`, `priority`:

```php
    protected function attachIssueStatus(Storage $storage, string $type, Collection $items): Collection
    {
        $statuses = $storage->issueStatuses($type, $items->pluck('key')->unique()->all());

        return $items->map(function ($item) use ($statuses, $type) {
            $found = $statuses->get($item->key);
            $item->issue_type = $type;
            $item->id = $found->id ?? null;
            $item->uuid = $found->uuid ?? null;
            $item->priority = $found->priority ?? 'none';
            $item->status = $found->status ?? 'open';
            $item->first_seen = $found->first_seen ?? $item->last_seen;

            return $item;
        });
    }
```

Finally, expose the selection state to the view — in `data()`'s returned array, add:

```php
            'selected' => $this->selected,
```

(anywhere in the array; e.g. right before the closing `'status' => $status,` entry.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=IssuesTest`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Issues.php tests/IssuesTest.php
git commit -m "feat: attach ticket id/uuid/priority to issue rows, add bulk select"
```

---

### Task 6: `IssueController` + routes

**Files:**
- Create: `src/Http/Controllers/IssueController.php`
- Modify: `routes/web.php`
- Test: `tests/MonitorTest.php`

**Interfaces:**
- Consumes: `Storage::findIssueByUuid()`/`setIssueStatus()`/`setIssuePriority()`/`openIssueCount()` (Task 3), `BuildsExceptionDetail` trait (Task 4), `Issues::STATUSES`/`Issues::PERFORMANCE_AREAS` (Task 5), `Format::PRIORITIES` (Task 2).
- Produces: routes `monitor.issues.show` (GET `/monitor/issues/{uuid}`), `monitor.issues.status` (POST `/monitor/issues/{uuid}/status`), `monitor.issues.priority` (POST `/monitor/issues/{uuid}/priority`). View data consumed by Task 8's `issue-detail-page.blade.php`: `issue`, `groups`, `footerTabs`, `openIssueCount`, `refresh`, `appInitial`, `statuses`, `priorities`, plus either the exception shape (`type: 'exception'`, `exists`, `class`, `message`, `handled`, `tz`, `phpVersion`, `laravelVersion`, `frameGroups`, `markdown`, `summary`, `occurrences`) or the performance shape (`type`, `badge`, `label`, `count`, `maxDuration`, `targetUrl`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/MonitorTest.php` (needs `use LaravelMonitor\Contracts\Storage;` if not already imported — check the top of the file first; it isn't, so add it alongside the other `use` statements):

```php
public function test_issue_detail_page_renders_an_exception_issue(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $key = Fingerprint::for('App\\Boom', 'Kaboom', 'app/X.php:10');

    Monitor::record('exception', $key, [
        'class' => 'App\\Services\\Boom',
        'message' => 'Kaboom',
        'file' => 'app/X.php',
        'line' => 10,
    ], null, 'unhandled');
    Monitor::flush();

    $storage = app(\LaravelMonitor\Contracts\Storage::class);
    $storage->syncIssues('exception', [$key => now()]);
    $uuid = $storage->issueStatuses('exception', [$key])->get($key)->uuid;

    $this->get('/monitor/issues/'.$uuid)
        ->assertOk()
        ->assertSeeText('Boom')
        ->assertSeeText('Kaboom')
        ->assertSeeText('Manage');
}

public function test_issue_detail_page_renders_a_performance_issue(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    Monitor::record('slow_query', 'select * from big_table', [], 600);
    Monitor::flush();

    $storage = app(\LaravelMonitor\Contracts\Storage::class);
    $storage->syncIssues('slow_query', ['select * from big_table' => now()]);
    $uuid = $storage->issueStatuses('slow_query', ['select * from big_table'])->get('select * from big_table')->uuid;

    $this->get('/monitor/issues/'.$uuid)
        ->assertOk()
        ->assertSeeText('Query')
        ->assertSeeText('Manage');
}

public function test_issue_detail_page_returns_404_for_an_unknown_uuid(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $this->get('/monitor/issues/'.(string) \Illuminate\Support\Str::uuid())->assertNotFound();
}

public function test_updating_issue_status_persists_and_redirects_back(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $storage = app(\LaravelMonitor\Contracts\Storage::class);
    $storage->setIssueStatus('exception', 'App\\Exceptions\\Boom', 'open');
    $uuid = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->uuid;

    $this->post('/monitor/issues/'.$uuid.'/status', ['status' => 'resolved'])
        ->assertRedirect('/monitor/issues/'.$uuid);

    $this->assertSame('resolved', $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->status);
}

public function test_updating_issue_priority_persists_and_redirects_back(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $storage = app(\LaravelMonitor\Contracts\Storage::class);
    $storage->setIssueStatus('exception', 'App\\Exceptions\\Boom', 'open');
    $uuid = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->uuid;

    $this->post('/monitor/issues/'.$uuid.'/priority', ['priority' => 'urgent'])
        ->assertRedirect('/monitor/issues/'.$uuid);

    $this->assertSame('urgent', $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->priority);
}

public function test_updating_issue_status_rejects_an_invalid_value(): void
{
    Gate::define('viewMonitor', fn ($user = null) => true);

    $storage = app(\LaravelMonitor\Contracts\Storage::class);
    $storage->setIssueStatus('exception', 'App\\Exceptions\\Boom', 'open');
    $uuid = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->uuid;

    $this->post('/monitor/issues/'.$uuid.'/status', ['status' => 'not-a-status'])
        ->assertSessionHasErrors('status');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_issue_detail_page`
Expected: FAIL — route `monitor.issues.show` doesn't exist yet (404 on every request).

- [ ] **Step 3: Create `IssueController`**

```php
<?php

namespace LaravelMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Livewire\Concerns\BuildsExceptionDetail;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Livewire\Issues;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Preferences;

/**
 * Renders the standalone Issue Detail page (route: monitor.issues.show) and
 * handles its Status/Priority mutations via plain POST + redirect back —
 * same convention as SettingsController — rather than a Livewire component,
 * since edits here are infrequent and don't need live reactivity. Owns its
 * own route, same family as RequestDetailController/JobAttemptController.
 */
class IssueController
{
    use BuildsExceptionDetail;
    use ResolvesUserNames;

    public function __construct(protected Storage $storage)
    {
    }

    public function show(string $uuid): View
    {
        app()->setLocale(Preferences::locale());

        $issue = $this->storage->findIssueByUuid($uuid);

        abort_unless($issue !== null, 404);

        [$groups, $footerTabs] = Nav::grouped();

        $shared = [
            'issue' => $issue,
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'openIssueCount' => $this->storage->openIssueCount(),
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'statuses' => Issues::STATUSES,
            'priorities' => Format::PRIORITIES,
        ];

        $data = $issue->type === 'exception'
            ? $this->exceptionData($issue->key)
            : $this->performanceData($issue->type, $issue->key);

        return view('monitor::issue-detail-page', $shared + $data);
    }

    public function updateStatus(Request $request, string $uuid): RedirectResponse
    {
        $issue = $this->storage->findIssueByUuid($uuid);

        abort_unless($issue !== null, 404);

        $status = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Issues::STATUSES)],
        ])['status'];

        $this->storage->setIssueStatus($issue->type, $issue->key, $status);

        return redirect()->route('monitor.issues.show', $uuid);
    }

    public function updatePriority(Request $request, string $uuid): RedirectResponse
    {
        $issue = $this->storage->findIssueByUuid($uuid);

        abort_unless($issue !== null, 404);

        $priority = $request->validate([
            'priority' => ['required', 'string', 'in:'.implode(',', array_keys(Format::PRIORITIES))],
        ])['priority'];

        $this->storage->setIssuePriority($issue->type, $issue->key, $priority);

        return redirect()->route('monitor.issues.show', $uuid);
    }

    protected function exceptionData(string $key): array
    {
        $since = CarbonImmutable::now()->subYears(5);
        $tz = Format::timezone();

        $group = $this->storage->exceptionGroups($since, null)->firstWhere('key', $key);
        $occurrences = $this->storage->recent('exception', $since, 200, null, $key, null);
        $latest = $occurrences->first();
        $payload = $latest->payload ?? [];

        $names = $this->resolveNames(
            $occurrences->pluck('user_id')->filter(fn ($id) => $id !== null)->unique()->all()
        );

        $servers = $occurrences->pluck('payload.server')->filter()->unique()->values();
        $handled = ($group?->unhandled ?? 0) === 0;

        $lastSeen = $group?->last_seen ?? $latest?->created_at;
        $firstSeen = $this->storage->firstSeen('exception', $key) ?? $group?->first_seen;
        $phpVersion = $payload['php_version'] ?? null;
        $laravelVersion = $payload['laravel_version'] ?? null;
        $occurrencesCount = $group?->count ?? $this->storage->stats('exception', $since, null, $key, null)->count;

        return [
            'type' => 'exception',
            'exists' => $latest !== null,
            'class' => $payload['class'] ?? $key,
            'message' => $payload['message'] ?? null,
            'handled' => $handled,
            'tz' => $tz,
            'phpVersion' => $phpVersion,
            'laravelVersion' => $laravelVersion,
            'frameGroups' => $this->frameGroups($payload['frames'] ?? []),
            'markdown' => $this->markdown($payload, $handled),
            'summary' => $this->summary($lastSeen, $firstSeen, $phpVersion, $laravelVersion, (int) ($group?->users ?? 0), $occurrencesCount, $servers, $tz),
            'occurrences' => $occurrences->take(50)->map(fn ($row) => (object) [
                'date' => Format::datetime($row->created_at),
                'server' => $row->payload['server'] ?? null,
                'message' => $row->payload['message'] ?? null,
                'user' => $row->user_id !== null ? ($names[$row->user_id] ?? "User #{$row->user_id}") : null,
            ]),
        ];
    }

    protected function performanceData(string $type, string $key): array
    {
        $area = Issues::PERFORMANCE_AREAS[$type] ?? null;

        abort_unless($area !== null, 404);

        $since = CarbonImmutable::now()->subYears(5);
        $stats = $this->storage->stats($type, $since, null, $key, null);

        abort_unless($stats->count > 0, 404);

        return [
            'type' => $type,
            'badge' => $area['badge'],
            'label' => $type === 'job' ? class_basename($key) : Str::limit($key, 100),
            'count' => $stats->count,
            'maxDuration' => $stats->max_duration,
            'targetUrl' => route('monitor.dashboard', ['tab' => $area['tab']] + (in_array($type, ['request', 'job'], true) ? ['key' => $key] : [])),
        ];
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add `use LaravelMonitor\Http\Controllers\IssueController;` to the `use` block, and add these three lines inside the `Route::group()` closure, right before the `Route::get('/{tab?}', ...)` catch-all (order matters: specific routes must be declared before the catch-all):

```php
        Route::get('/issues/{uuid}', [IssueController::class, 'show'])->name('monitor.issues.show');
        Route::post('/issues/{uuid}/status', [IssueController::class, 'updateStatus'])->name('monitor.issues.status');
        Route::post('/issues/{uuid}/priority', [IssueController::class, 'updatePriority'])->name('monitor.issues.priority');
```

The full group becomes:

```php
Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/requests/{requestId}', RequestDetailController::class)->name('monitor.requests.show');
        Route::get('/jobs/attempts/{attemptId}', JobAttemptController::class)->name('monitor.jobs.attempts.show');
        Route::get('/commands/runs/{runId}', CommandRunController::class)->name('monitor.commands.runs.show');
        Route::get('/issues/{uuid}', [IssueController::class, 'show'])->name('monitor.issues.show');
        Route::post('/issues/{uuid}/status', [IssueController::class, 'updateStatus'])->name('monitor.issues.status');
        Route::post('/issues/{uuid}/priority', [IssueController::class, 'updatePriority'])->name('monitor.issues.priority');
        Route::post('/settings/system', [SettingsController::class, 'system'])->name('monitor.settings.system');
        Route::post('/settings/reset', [SettingsController::class, 'reset'])->name('monitor.settings.reset');
        Route::get('/{tab?}', DashboardController::class)->name('monitor.dashboard');
    });
```

- [ ] **Step 5: This step intentionally left to Task 8**

The tests written in Step 1 won't pass yet — `monitor::issue-detail-page` view doesn't exist. Do not run them again until Task 8 is done; move on.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/IssueController.php routes/web.php tests/MonitorTest.php
git commit -m "feat: add IssueController and /monitor/issues/{uuid} routes"
```

---

### Task 7: Issues list becomes a table with checkboxes, priority icon, and a bulk action bar

**Files:**
- Modify: `resources/views/livewire/issues.blade.php`
- Modify: `src/Support/Icons.php`

**Interfaces:**
- Consumes: `$exceptions`/`$performance` rows now carrying `id`/`uuid`/`priority` (Task 5), `$selected` (Task 5), `toggleSelected()`/`selectAll()`/`resolveSelected()`/`ignoreSelected()` (Task 5), `Format::PRIORITIES` (Task 2), route('monitor.issues.show', `$uuid`) (Task 6).
- Produces: `Icons::PRIORITY` (new SVG path constant — a simple 3-bar ascending "signal" glyph).

- [ ] **Step 1: Add the priority icon**

In `src/Support/Icons.php`, add this constant (anywhere among the other constants, e.g. after `ISSUES`):

```php
    /**
     * Three ascending bars — used as the read-only priority indicator on
     * the Issues list (colour communicates the level; see issues.blade.php).
     */
    public const PRIORITY = 'M4 19v-6M10 19v-10M16 19v-14';
```

- [ ] **Step 2: Rewrite `resources/views/livewire/issues.blade.php`**

Replace the entire file with:

```php
@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $glitch = collect(range(1, 60))->map(fn ($i) => strtoupper(base_convert(md5('nightwatch'.$i), 16, 36)))->implode(' ');
    $actionButton = 'shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50 hover:text-neutral-900 dark:hover:text-neutral-100';
    $priorityColor = fn (string $priority) => match ($priority) {
        'urgent' => 'text-rose-500',
        'high' => 'text-orange-500',
        'medium' => 'text-amber-500',
        'low' => 'text-blue-500',
        default => 'text-neutral-300 dark:text-neutral-600',
    };
    $selectedCount = array_sum(array_map('count', $selected));
    $rows = $view === 'exceptions' ? $exceptions : $performance;
    $allSelectedOnPage = $rows->isNotEmpty() && $rows->every(
        fn ($row) => isset($selected[$view === 'exceptions' ? 'exception' : $row->issue_type][$row->key])
    );
    $pagePairs = $view === 'exceptions'
        ? $exceptions->map(fn ($e) => ['exception', $e->key])->values()
        : $performance->map(fn ($item) => [$item->issue_type, $item->key])->values();
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex h-9 items-center gap-0.5 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-0.5 shadow-sm">
            @foreach (['exceptions' => ['Exceptions', $exceptionCount], 'performance' => ['Performance', $performanceCount]] as $issueTab => [$issueLabel, $issueCount])
                <button type="button" wire:click="$set('view', '{{ $issueTab }}')"
                        @class([
                            'flex h-full items-center gap-2 rounded-md border px-3 text-sm',
                            'border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 text-neutral-900 dark:text-neutral-100' => $view === $issueTab,
                            'border-transparent text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100' => $view !== $issueTab,
                        ])>
                    {{ $issueLabel }}
                    <span class="rounded bg-neutral-200/80 dark:bg-neutral-700/80 px-1.5 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ $issueCount }}</span>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search"
                       class="h-9 w-52 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-3 text-sm text-neutral-700 dark:text-neutral-200 shadow-sm placeholder:text-neutral-400 dark:placeholder:text-neutral-500 focus:outline-none">
            </div>
            <div class="flex h-9 items-center gap-0.5 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-0.5 text-sm shadow-sm">
                @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $statusKey => $statusLabel)
                    <button type="button" wire:click="$set('status', '{{ $statusKey }}')"
                            @class([
                                'flex h-full items-center rounded-md border px-3',
                                'border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 text-neutral-900 dark:text-neutral-100' => $status === $statusKey,
                                'border-transparent text-neutral-400 dark:text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100' => $status !== $statusKey,
                            ])>{{ $statusLabel }}</button>
                @endforeach
            </div>
        </div>
    </div>

    @if ($selectedCount > 0)
        <div class="mt-3 flex items-center gap-3 rounded-lg border border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 px-3 py-2 text-sm">
            <span class="font-medium text-blue-700 dark:text-blue-300">{{ $selectedCount }} selected</span>
            <button type="button" wire:click="resolveSelected" class="{{ $actionButton }}">Resolve</button>
            <button type="button" wire:click="ignoreSelected" class="{{ $actionButton }}">Ignore</button>
            <button type="button" wire:click="deselectAll" class="ml-auto text-xs text-blue-700 dark:text-blue-300 hover:underline">Clear</button>
        </div>
    @endif

    <div class="mt-4">
        @if ($view === 'exceptions' && $exceptions->isNotEmpty())
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="w-8 pb-2">
                                    <input type="checkbox" @checked($allSelectedOnPage)
                                           wire:click="{{ $allSelectedOnPage ? 'deselectAll' : 'selectAll' }}({{ $allSelectedOnPage ? '' : Js::from($pagePairs) }})">
                                </th>
                                <th class="w-12 pb-2 font-normal">#</th>
                                <th class="w-8 pb-2 font-normal"></th>
                                <th class="pb-2 font-normal">Issue</th>
                                <th class="pb-2 text-right font-normal">Count</th>
                                <th class="pb-2 text-right font-normal">Users</th>
                                <th class="pb-2 text-right font-normal">First seen</th>
                                <th class="pb-2 text-right font-normal">Last seen</th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($exceptions as $exception)
                                <tr class="group hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="py-2.5 pr-2">
                                        <input type="checkbox" @checked(isset($selected['exception'][$exception->key]))
                                               wire:click="toggleSelected('exception', '{{ $exception->key }}')">
                                    </td>
                                    <td class="py-2.5 pr-2 font-mono text-xs text-neutral-400 dark:text-neutral-500">#{{ $exception->id }}</td>
                                    <td class="py-2.5 pr-2" title="{{ Format::priorityLabel($exception->priority) }}">
                                        <x-monitor::icon :path="Icons::PRIORITY" :stroke="2" class="h-4 w-4 {{ $priorityColor($exception->priority) }}"/>
                                    </td>
                                    <td class="max-w-[26rem] cursor-pointer py-2.5 pr-3" onclick="window.location='{{ route('monitor.issues.show', $exception->uuid) }}'">
                                        <p class="truncate font-mono text-xs font-medium text-neutral-800 dark:text-neutral-200">{{ class_basename($exception->latest['class'] ?? $exception->key) }}</p>
                                        @if (($exception->latest['message'] ?? '') !== '')
                                            <p class="mt-0.5 line-clamp-1 text-xs text-neutral-400 dark:text-neutral-500">{{ $exception->latest['message'] }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ number_format($exception->count) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-500 dark:text-neutral-400">—</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $exception->first_seen?->diffForHumans() }}">{{ $exception->first_seen?->diffForHumans(short: true) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $exception->last_seen?->diffForHumans() }}">{{ $exception->last_seen?->diffForHumans(short: true) }}</td>
                                    <td class="py-2.5 pl-2 text-right">
                                        @if ($exception->status === 'open')
                                            <button type="button" wire:click="resolve('exception', '{{ $exception->key }}')" class="{{ $actionButton }}">Resolve</button>
                                        @else
                                            <button type="button" wire:click="reopen('exception', '{{ $exception->key }}')" class="{{ $actionButton }}">Reopen</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-monitor::card>
        @elseif ($view === 'performance' && $performance->isNotEmpty())
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="w-8 pb-2">
                                    <input type="checkbox" @checked($allSelectedOnPage)
                                           wire:click="{{ $allSelectedOnPage ? 'deselectAll' : 'selectAll' }}({{ $allSelectedOnPage ? '' : Js::from($pagePairs) }})">
                                </th>
                                <th class="w-12 pb-2 font-normal">#</th>
                                <th class="w-8 pb-2 font-normal"></th>
                                <th class="pb-2 font-normal">Issue</th>
                                <th class="pb-2 text-right font-normal">Count</th>
                                <th class="pb-2 text-right font-normal">Max</th>
                                <th class="pb-2 text-right font-normal">Last seen</th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($performance as $item)
                                <tr class="group hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="py-2.5 pr-2">
                                        <input type="checkbox" @checked(isset($selected[$item->issue_type][$item->key]))
                                               wire:click="toggleSelected('{{ $item->issue_type }}', '{{ $item->key }}')">
                                    </td>
                                    <td class="py-2.5 pr-2 font-mono text-xs text-neutral-400 dark:text-neutral-500">#{{ $item->id }}</td>
                                    <td class="py-2.5 pr-2" title="{{ Format::priorityLabel($item->priority) }}">
                                        <x-monitor::icon :path="Icons::PRIORITY" :stroke="2" class="h-4 w-4 {{ $priorityColor($item->priority) }}"/>
                                    </td>
                                    <td class="max-w-[26rem] cursor-pointer py-2.5 pr-3" onclick="window.location='{{ route('monitor.issues.show', $item->uuid) }}'">
                                        <span class="mr-2 shrink-0 rounded border border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $item->badge }}</span>
                                        <span class="font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $item->label }}</span>
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ number_format($item->count) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-amber-600 dark:text-amber-400">{{ $fmt($item->max_duration) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $item->last_seen?->diffForHumans() }}">{{ $item->last_seen?->diffForHumans(short: true) }}</td>
                                    <td class="py-2.5 pl-2 text-right">
                                        @if ($item->status === 'open')
                                            <button type="button" wire:click="resolve('{{ $item->issue_type }}', '{{ $item->key }}')" class="{{ $actionButton }}">Resolve</button>
                                        @else
                                            <button type="button" wire:click="reopen('{{ $item->issue_type }}', '{{ $item->key }}')" class="{{ $actionButton }}">Reopen</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-monitor::card>
        @else
            <x-monitor::card class="relative overflow-hidden p-4">
                <p class="select-none break-all font-mono text-xs leading-6 text-neutral-200" aria-hidden="true">{{ $glitch }}</p>
                <span class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 bg-amber-200 px-1.5 py-0.5 font-mono text-xs tracking-tight text-neutral-900">NO ISSUES FOUND</span>
            </x-monitor::card>
        @endif
    </div>
</div>
```

Notes on what changed from the previous card-list version:
- `Users` column always renders `—` for exceptions here because `Issues::data()` doesn't currently compute a per-key user count (unlike the Exceptions tab's own list, which does via `topUsers()`); wiring that up is future work, out of this plan's scope per the spec.
- The `ignore` action button was dropped from the per-row actions to fit the narrower table row (Resolve/Reopen only) — bulk Ignore is still available via the selection bar. This is a deliberate trim to keep the row from overflowing; flag it to the user after this task if they'd rather keep a 3-button row.

- [ ] **Step 3: Syntax-check the view**

Run: `/opt/homebrew/bin/php -l resources/views/livewire/issues.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/issues.blade.php src/Support/Icons.php
git commit -m "feat: redesign Issues list as a table with checkboxes and priority"
```

---

### Task 8: `issue-detail-page.blade.php` + Manage panel component

**Files:**
- Create: `resources/views/issue-detail-page.blade.php`
- Create: `resources/views/components/issues/manage-panel.blade.php`

**Interfaces:**
- Consumes: everything `IssueController::show()` passes to the view (Task 6).

- [ ] **Step 1: Create the Manage panel component**

`resources/views/components/issues/manage-panel.blade.php`:

```php
{{-- Status + Priority controls for the standalone Issue detail page.
     Plain POST-and-redirect forms (see Http\Controllers\IssueController),
     not Livewire — matches the SettingsController convention already used
     for infrequent, non-reactive mutations on this dashboard. --}}
@props(['issue', 'statuses', 'priorities'])
<div class="rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
    <p class="border-b border-neutral-100 dark:border-neutral-800 px-4 py-3 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Manage</p>

    <div class="space-y-4 p-4">
        <div>
            <label class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Status</label>
            <form method="POST" action="{{ route('monitor.issues.status', $issue->uuid) }}" class="mt-2 flex gap-1 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 p-0.5">
                @csrf
                @foreach ($statuses as $status)
                    <button type="submit" name="status" value="{{ $status }}"
                            @class([
                                'flex-1 rounded-md px-2 py-1 text-xs capitalize',
                                'bg-white dark:bg-neutral-900 text-neutral-900 dark:text-neutral-100 shadow-sm' => $issue->status === $status,
                                'text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100' => $issue->status !== $status,
                            ])>{{ $status }}</button>
                @endforeach
            </form>
        </div>

        <div>
            <label for="priority" class="block font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Priority</label>
            <form method="POST" action="{{ route('monitor.issues.priority', $issue->uuid) }}" class="mt-2">
                @csrf
                <select name="priority" id="priority" onchange="this.form.submit()"
                        class="w-full rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-sm text-neutral-700 dark:text-neutral-200 focus:outline-none">
                    @foreach ($priorities as $value => $label)
                        <option value="{{ $value }}" @selected($issue->priority === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Create the page**

`resources/views/issue-detail-page.blade.php`:

```php
{{-- Standalone Issue Detail page (route: monitor.issues.show). Shows the
     full picture for one exception, or a compact summary for a
     performance-threshold issue, plus a Manage panel (Status/Priority).
     See Http\Controllers\IssueController. --}}
<x-monitor::layout :title="'Issue #'.$issue->id">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="'issues'" :range="[]" :refresh="$refresh" :app-initial="$appInitial" :open-issue-count="$openIssueCount"/>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
                <div class="mx-auto flex w-full max-w-[1600px] items-center gap-3 px-4 py-5 md:px-8">
                    <a href="{{ route('monitor.dashboard', ['tab' => 'issues']) }}" class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">← Issues</a>
                    <span class="font-mono text-xs text-neutral-400 dark:text-neutral-500">#{{ $issue->id }}</span>
                </div>
            </header>

            <main class="mx-auto grid w-full max-w-[1600px] flex-1 grid-cols-1 gap-4 px-4 pb-10 md:px-8 lg:grid-cols-[1fr_260px]">
                <div class="min-w-0 space-y-4">
                    @if ($type === 'exception')
                        @if (! $exists)
                            <x-monitor::card class="p-10 text-center">
                                <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Exception not found</p>
                                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">This exception has no recorded occurrences.</p>
                            </x-monitor::card>
                        @else
                            <x-monitor::card class="p-4">
                                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Summary</p>
                                <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3">
                                    @foreach ($summary as [$label, $value])
                                        <div class="flex max-w-full items-baseline gap-2 h-6 text-sm font-mono">
                                            <div class="uppercase text-neutral-500 dark:text-neutral-400 shrink-0">{{ $label }}</div>
                                            <div class="min-w-6 grow h-3 border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                                            <div class="truncate text-neutral-900 dark:text-white">{{ $value }}</div>
                                        </div>
                                    @endforeach
                                </dl>
                            </x-monitor::card>

                            <div class="flex flex-col rounded-lg border border-neutral-200 bg-white shadow-md shadow-black/5 dark:border-white/5 dark:bg-white/2 dark:shadow-black/20 overflow-hidden">
                                <div class="flex flex-col gap-3 p-4 md:p-5"
                                     x-data="{ copied: false, copy() {
                                         navigator.clipboard.writeText(@js($markdown)).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1600); });
                                     } }">
                                    <div class="flex justify-between gap-2 max-md:flex-col md:items-center">
                                        <x-monitor::status-badge :handled="$handled"/>
                                        <button type="button" @click="copy()"
                                                class="group flex h-6 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white/50 dark:bg-neutral-900/50 px-1.5 text-xs leading-none text-neutral-600 dark:text-neutral-300 hover:border-blue-500 hover:bg-white dark:hover:bg-neutral-900 hover:text-neutral-900 dark:hover:text-neutral-100 active:translate-y-px active:bg-neutral-100 dark:active:bg-neutral-800">
                                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500 group-hover:text-blue-600 dark:group-hover:text-blue-400"/>
                                            <span x-text="copied ? 'Copied!' : 'Copy as Markdown'"></span>
                                        </button>
                                    </div>
                                    <div class="mt-1 min-w-0 flex-1 break-all text-2xl/none font-semibold {{ $handled ? 'text-neutral-900 dark:text-neutral-100' : 'text-rose-600 dark:text-rose-400' }}" title="{{ $class }}">{{ $class }}</div>
                                    @if (filled($message))
                                        <p class="break-words text-sm text-neutral-600 dark:text-neutral-300">{{ $message }}</p>
                                    @endif
                                </div>
                                @if (! empty($frameGroups))
                                    <x-monitor::stack-trace :groups="$frameGroups"/>
                                @else
                                    <x-monitor::card class="p-8 text-center text-sm text-neutral-400 dark:text-neutral-500">No stack trace was captured for this exception.</x-monitor::card>
                                @endif
                            </div>

                            <div>
                                <div class="flex items-center gap-2 px-1 pb-3">
                                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOCK" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
                                    <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($occurrences->count()) }} {{ $occurrences->count() === 1 ? 'Occurrence' : 'Occurrences' }}</h3>
                                </div>
                                <x-monitor::card class="p-4">
                                    <div class="overflow-x-auto">
                                        <table class="w-full min-w-[640px] text-sm">
                                            <thead>
                                                <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                                    <th class="pb-2 font-normal">Date</th>
                                                    <th class="pb-2 font-normal">Source</th>
                                                    <th class="pb-2 font-normal">Message</th>
                                                    <th class="pb-2 font-normal">User</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                                @foreach ($occurrences as $occurrence)
                                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                                        <td class="whitespace-nowrap py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $occurrence->date }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                                        <td class="py-2 pr-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $occurrence->server ?? '—' }}</td>
                                                        <td class="max-w-[22rem] truncate py-2 pr-3 text-xs text-neutral-600 dark:text-neutral-300" title="{{ $occurrence->message }}">{{ $occurrence->message ?? '—' }}</td>
                                                        <td class="py-2 pr-3 text-xs text-neutral-600 dark:text-neutral-300">{{ $occurrence->user ?? '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </x-monitor::card>
                            </div>
                        @endif
                    @else
                        <x-monitor::card class="p-6">
                            <span class="rounded border border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $badge }}</span>
                            <p class="mt-3 break-all font-mono text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $label }}</p>
                            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Occurrences</dt>
                                    <dd class="mt-1 font-mono text-neutral-900 dark:text-neutral-100">{{ number_format($count) }}</dd>
                                </div>
                                <div>
                                    <dt class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Max duration</dt>
                                    <dd class="mt-1 font-mono text-amber-600 dark:text-amber-400">{{ \LaravelMonitor\Support\Format::duration($maxDuration) }}</dd>
                                </div>
                            </dl>
                            <a href="{{ $targetUrl }}" class="mt-4 inline-block text-sm text-blue-600 dark:text-blue-400 hover:underline">View details →</a>
                        </x-monitor::card>
                    @endif
                </div>

                <aside class="lg:sticky lg:top-20 lg:self-start">
                    <x-monitor::issues.manage-panel :issue="$issue" :statuses="$statuses" :priorities="$priorities"/>
                </aside>
            </main>
        </div>
    </div>
</x-monitor::layout>
```

- [ ] **Step 3: Syntax-check both views**

Run: `/opt/homebrew/bin/php -l resources/views/issue-detail-page.blade.php && /opt/homebrew/bin/php -l resources/views/components/issues/manage-panel.blade.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Run the Task 6 tests, now that the view exists**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_issue_detail_page`
Expected: PASS (all three: renders exception, renders performance, 404 for unknown uuid)

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --filter=test_updating_issue`
Expected: PASS (all three: status persists, priority persists, invalid status rejected)

- [ ] **Step 5: Run the full suite**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS — every test in the suite, including everything from Tasks 1–7.

- [ ] **Step 6: Commit**

```bash
git add resources/views/issue-detail-page.blade.php resources/views/components/issues/manage-panel.blade.php
git commit -m "feat: add the standalone Issue detail page with a Manage panel"
```

---

### Task 9: End-to-end verification, push, PR update

**Files:** none (verification + git/GitHub operations only)

- [ ] **Step 1: Run the full test suite one more time**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit`
Expected: PASS, all tests (this repo has no separate lint/Pint step wired into local verification in this session so far — CI's `lint` job runs Pint automatically on push; rely on that rather than running Pint locally unless it's confirmed installed).

- [ ] **Step 2: Manual smoke check in the browser**

Using the already-running `laravel-newest` dev server (symlinked to this package) and the Chrome tab from earlier in this session:
1. `cd /Users/manh.nguyen3/Work/laravel-newest && /opt/homebrew/bin/php artisan migrate --force` (picks up Task 1's migration).
2. `/opt/homebrew/bin/php artisan view:clear`.
3. Navigate to `http://127.0.0.1:8123/monitor/issues`, confirm the table renders with `#id`, priority icons, checkboxes, and that clicking an exception's title opens `/monitor/issues/{uuid}` with a working Manage panel (change Status/Priority, confirm it persists after the redirect).

- [ ] **Step 3: Push and update the PR**

```bash
git push origin feat/nightwatch-parity-improvements
gh pr edit 13 --title "feat: Nightwatch-style Issues page (ticket IDs, priority, dedicated detail page)" --body "$(cat <<'EOF'
## Summary
- Adds a Nightwatch-style ticket number (#id) and manual priority field to Issues (exceptions + performance breaches).
- Redesigns the Issues list as a sortable-looking table with bulk checkbox Resolve/Ignore.
- Adds a dedicated /monitor/issues/{uuid} detail page with a Status/Priority "Manage" panel (exceptions get the full stack-trace/occurrences view; performance issues get a compact summary + link back to their own tab).
- Sidebar "Issues" badge showing the open-issue count.
- Fixes the Mail/Notifications duration-chart ordering and the exception-name-shows-a-hash-instead-of-the-class bug found earlier on this branch.
- CI: pins orchestra/testbench per matrix.laravel leg so prefer-lowest/prefer-stable actually exercise each declared Laravel major instead of collapsing to the same resolved version.

Design doc: docs/superpowers/specs/2026-07-19-issues-nightwatch-parity-design.md
Implementation plan: docs/superpowers/plans/2026-07-19-issues-nightwatch-parity.md

## Test plan
- [x] `vendor/bin/phpunit` full suite green locally
- [ ] CI green across the PHP/Laravel/Livewire/DB matrix
EOF
)"
```

- [ ] **Step 4: Watch CI, fix and re-push if anything fails**

Poll `gh pr checks 13` until every check settles. For any failure, read the job log (`gh api repos/ntm-dev/laravel-monitor/actions/jobs/<id>/logs`), fix the root cause locally, re-run the full suite, commit, and push again — repeat until the PR is fully green, per this session's established pattern (see the earlier `Str::uuid7()`-vs-`Ramsey\Uuid` compatibility issue this exact plan was written to avoid).
