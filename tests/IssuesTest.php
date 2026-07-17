<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Livewire\Issues;
use Livewire\Livewire;

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
}
