<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Livewire\Issues;
use LaravelMonitor\Support\RecordType;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;

class IssuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_tab_merges_every_area_over_its_own_threshold(): void
    {
        // Each pair straddles that area's own configured default threshold
        // (request/job 1000ms, query 500ms, outgoing_request 1000ms) — the
        // "under" entry of each pair must be excluded, proving the areas
        // aren't sharing a single threshold.
        Monitor::record(RecordType::Request, 'GET /over', [], 1200, '2xx');
        Monitor::record(RecordType::Request, 'GET /under', [], 800, '2xx');
        Monitor::record(RecordType::Job, 'App\\Jobs\\SlowJob', [], 1500, 'processed');
        Monitor::record(RecordType::Job, 'App\\Jobs\\FastJob', [], 200, 'processed');
        Monitor::record(RecordType::Query, 'select * from big_table', [], 600);
        Monitor::record(RecordType::Query, 'select * from small_table', [], 400);
        Monitor::record(RecordType::OutgoingRequest, 'GET https://slow.example.com', [], 3000, 'success');
        Monitor::record(RecordType::OutgoingRequest, 'GET https://fast.example.com', [], 100, 'success');
        Monitor::flush();

        $component = Livewire::test(Issues::class)->set('view', 'performance');

        $performance = $component->viewData('performance');

        $this->assertCount(4, $performance);
        $this->assertSame(
            ['Outgoing', 'Job', 'Request', 'Query'],
            $performance->pluck('badge')->all(),
            'expected worst max_duration first',
        );
        $this->assertTrue($performance->pluck('label')->contains('GET /over'));
        $this->assertFalse($performance->pluck('label')->contains('GET /under'));
    }

    public function test_resolving_an_exception_moves_it_out_of_the_open_tab(): void
    {
        Monitor::record(RecordType::Exception, 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
        Monitor::flush();

        $component = Livewire::test(Issues::class)->set('view', 'exceptions');

        $this->assertCount(1, $component->viewData('exceptions'));

        $component->call('resolve', 'exception', 'App\\Exceptions\\Boom');

        $this->assertCount(0, $component->viewData('exceptions'));

        $resolved = $component->set('status', 'resolved')->viewData('exceptions');
        $this->assertCount(1, $resolved);
        $this->assertSame('resolved', $resolved->first()->status);
    }

    public function test_open_tab_shows_the_exception_class_not_the_raw_key_once_its_entry_ages_out_of_the_recent_sample(): void
    {
        // Fingerprint-style key, deliberately different from the class name
        // it belongs to — a real Exceptions recorder key is a hash, not the
        // class itself, so this is the only way the test can tell "resolved
        // from payload" apart from "fell back to the raw key".
        Monitor::record(RecordType::Exception, 'old-fingerprint-hash', ['class' => 'App\\Exceptions\\OldBug', 'message' => 'old boom'], null, 'unhandled');

        // Enough newer occurrences, under other keys, to push the row above
        // out of the fixed-size "most recent entries" window the Issues
        // list used to resolve each group's label from.
        for ($i = 0; $i < 105; $i++) {
            Monitor::record(RecordType::Exception, "noise-{$i}", ['class' => 'App\\Exceptions\\Noise', 'message' => 'noise'], null, 'unhandled');
        }

        Monitor::flush();

        $component = Livewire::test(Issues::class)->set('view', 'exceptions');

        $oldBug = $component->viewData('exceptions')->firstWhere('key', 'old-fingerprint-hash');

        $this->assertNotNull($oldBug);
        $this->assertSame('App\\Exceptions\\OldBug', $oldBug->label);
    }

    public function test_ignoring_a_performance_issue_moves_it_out_of_the_open_tab(): void
    {
        Monitor::record(RecordType::Query, 'select * from big_table', [], 600);
        Monitor::flush();

        $component = Livewire::test(Issues::class)->set('view', 'performance');

        $this->assertCount(1, $component->viewData('performance'));

        $component->call('ignore', 'query', 'select * from big_table');

        $this->assertCount(0, $component->viewData('performance'));

        $ignored = $component->set('status', 'ignored')->viewData('performance');
        $this->assertCount(1, $ignored);
        $this->assertSame('ignored', $ignored->first()->status);
    }

    public function test_a_performance_issue_is_deleted_once_it_falls_back_under_its_threshold(): void
    {
        // Opens while the threshold is low enough for this query to breach
        // it — mirrors an issue that was genuinely "open" at some point.
        config(['monitor.thresholds.query' => 500]);

        Monitor::record(RecordType::Query, 'select * from big_table', [], 600);
        Monitor::flush();

        Livewire::test(Issues::class)->set('view', 'performance');

        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $this->assertSame('open', $storage->issueStatuses('query', ['select * from big_table'])->get('select * from big_table')->status);

        // Threshold raised past the query's own max_duration — it no
        // longer belongs on the page, and shouldn't keep counting toward
        // openIssueCount() either.
        config(['monitor.thresholds.query' => 1000]);

        Livewire::test(Issues::class)->set('view', 'performance');

        $this->assertNull($storage->issueStatuses('query', ['select * from big_table'])->get('select * from big_table'));
        $this->assertSame(0, $storage->openIssueCount());
    }

    public function test_prune_command_also_deletes_a_stale_performance_issue_below_its_current_threshold(): void
    {
        // Same scenario as the Livewire-driven test above, but reached via
        // `monitor:prune` directly — this issue must not depend on someone
        // happening to have the dashboard open to get cleaned up.
        config(['monitor.thresholds.query' => 500]);

        Monitor::record(RecordType::Query, 'select * from big_table', [], 600);
        Monitor::flush();

        Livewire::test(Issues::class)->set('view', 'performance');

        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $this->assertSame('open', $storage->issueStatuses('query', ['select * from big_table'])->get('select * from big_table')->status);

        config(['monitor.thresholds.query' => 1000]);

        // A huge --hours keeps purge() from touching the entry itself —
        // isolates this test to the reconciliation pass alone.
        $this->artisan('monitor:prune', ['--hours' => 999999])->assertSuccessful();

        $this->assertNull($storage->issueStatuses('query', ['select * from big_table'])->get('select * from big_table'));
    }

    public function test_reopening_a_resolved_exception_returns_it_to_the_open_tab(): void
    {
        Monitor::record(RecordType::Exception, 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
        Monitor::flush();

        $component = Livewire::test(Issues::class)->set('view', 'exceptions');
        $component->call('resolve', 'exception', 'App\\Exceptions\\Boom');
        $component->call('reopen', 'exception', 'App\\Exceptions\\Boom');

        $open = $component->set('status', 'open')->viewData('exceptions');
        $this->assertCount(1, $open);
        $this->assertSame('open', $open->first()->status);
    }

    public function test_a_resolved_exception_that_recurs_reopens_itself(): void
    {
        $storage = app(\LaravelMonitor\Contracts\Storage::class);

        \Illuminate\Support\Carbon::setTestNow(now()->subMinutes(5));
        $storage->setIssueStatus('exception', 'App\\Exceptions\\Boom', 'resolved');

        // Recorded strictly after the resolution above — timestamp columns
        // are only second-precision, so the gap needs to survive that.
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(6));
        Monitor::record(RecordType::Exception, 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom again'], null, 'unhandled');
        Monitor::flush();

        $open = Livewire::test(Issues::class)->set('view', 'exceptions')->viewData('exceptions');

        $this->assertCount(1, $open);
        $this->assertSame('open', $open->first()->status);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_an_ignored_performance_issue_does_not_reopen_on_recurrence(): void
    {
        $storage = app(\LaravelMonitor\Contracts\Storage::class);

        Monitor::record(RecordType::Query, 'select * from big_table', [], 600);
        Monitor::flush();

        $storage->setIssueStatus('query', 'select * from big_table', 'ignored');

        Monitor::record(RecordType::Query, 'select * from big_table', [], 700);
        Monitor::flush();

        $open = Livewire::test(Issues::class)->set('view', 'performance')->viewData('performance');

        $this->assertCount(0, $open);
    }

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

        Monitor::record(RecordType::Exception, 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
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

    public function test_delete_missing_issues_deletes_only_the_absent_open_keys(): void
    {
        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->syncIssues('query', ['keep' => now(), 'drop' => now()]);

        $deleted = $storage->deleteMissingIssues('query', ['keep']);

        $this->assertSame(1, $deleted);
        $statuses = $storage->issueStatuses('query', ['keep', 'drop']);
        $this->assertSame('open', $statuses->get('keep')->status);
        $this->assertNull($statuses->get('drop'));
    }

    public function test_delete_missing_issues_with_no_current_keys_deletes_every_open_issue_of_that_type(): void
    {
        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->syncIssues('query', ['a' => now(), 'b' => now()]);

        $storage->deleteMissingIssues('query', []);

        $statuses = $storage->issueStatuses('query', ['a', 'b']);
        $this->assertNull($statuses->get('a'));
        $this->assertNull($statuses->get('b'));
    }

    public function test_delete_missing_issues_leaves_ignored_issues_alone(): void
    {
        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->syncIssues('query', ['a' => now()]);
        $storage->setIssueStatus('query', 'a', 'ignored');

        $storage->deleteMissingIssues('query', []);

        $this->assertSame('ignored', $storage->issueStatuses('query', ['a'])->get('a')->status);
    }

    public function test_exception_rows_carry_id_uuid_and_priority(): void
    {
        Monitor::record(RecordType::Exception, 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
        Monitor::flush();

        $row = Livewire::test(Issues::class)->set('view', 'exceptions')->viewData('exceptions')->first();

        $this->assertNotNull($row->id);
        $this->assertSame(36, strlen($row->uuid));
        $this->assertSame('none', $row->priority);
    }

    public function test_bulk_resolving_selected_exceptions_moves_them_out_of_the_open_tab(): void
    {
        Monitor::record(RecordType::Exception, 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
        Monitor::record(RecordType::Exception, 'App\\Exceptions\\Bang', ['class' => 'App\\Exceptions\\Bang', 'message' => 'bang'], null, 'unhandled');
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
}
