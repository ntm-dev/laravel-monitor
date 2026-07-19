<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Livewire\Issues;
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
        Monitor::record('request', 'GET /over', [], 1200, '2xx');
        Monitor::record('request', 'GET /under', [], 800, '2xx');
        Monitor::record('job', 'App\\Jobs\\SlowJob', [], 1500, 'processed');
        Monitor::record('job', 'App\\Jobs\\FastJob', [], 200, 'processed');
        Monitor::record('slow_query', 'select * from big_table', [], 600);
        Monitor::record('slow_query', 'select * from small_table', [], 400);
        Monitor::record('outgoing_request', 'GET https://slow.example.com', [], 3000, 'success');
        Monitor::record('outgoing_request', 'GET https://fast.example.com', [], 100, 'success');
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
        Monitor::record('exception', 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
        Monitor::flush();

        $component = Livewire::test(Issues::class)->set('view', 'exceptions');

        $this->assertCount(1, $component->viewData('exceptions'));

        $component->call('resolve', 'exception', 'App\\Exceptions\\Boom');

        $this->assertCount(0, $component->viewData('exceptions'));

        $resolved = $component->set('status', 'resolved')->viewData('exceptions');
        $this->assertCount(1, $resolved);
        $this->assertSame('resolved', $resolved->first()->status);
    }

    public function test_ignoring_a_performance_issue_moves_it_out_of_the_open_tab(): void
    {
        Monitor::record('slow_query', 'select * from big_table', [], 600);
        Monitor::flush();

        $component = Livewire::test(Issues::class)->set('view', 'performance');

        $this->assertCount(1, $component->viewData('performance'));

        $component->call('ignore', 'slow_query', 'select * from big_table');

        $this->assertCount(0, $component->viewData('performance'));

        $ignored = $component->set('status', 'ignored')->viewData('performance');
        $this->assertCount(1, $ignored);
        $this->assertSame('ignored', $ignored->first()->status);
    }

    public function test_reopening_a_resolved_exception_returns_it_to_the_open_tab(): void
    {
        Monitor::record('exception', 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom'], null, 'unhandled');
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
        Monitor::record('exception', 'App\\Exceptions\\Boom', ['class' => 'App\\Exceptions\\Boom', 'message' => 'boom again'], null, 'unhandled');
        Monitor::flush();

        $open = Livewire::test(Issues::class)->set('view', 'exceptions')->viewData('exceptions');

        $this->assertCount(1, $open);
        $this->assertSame('open', $open->first()->status);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_an_ignored_performance_issue_does_not_reopen_on_recurrence(): void
    {
        $storage = app(\LaravelMonitor\Contracts\Storage::class);

        Monitor::record('slow_query', 'select * from big_table', [], 600);
        Monitor::flush();

        $storage->setIssueStatus('slow_query', 'select * from big_table', 'ignored');

        Monitor::record('slow_query', 'select * from big_table', [], 700);
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
}
