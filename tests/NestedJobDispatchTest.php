<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Recorders\Jobs;
use LaravelMonitor\Support\RecordType;

/**
 * A job that dispatches further jobs records their 'queued' placeholders
 * under its OWN request_id and type, so within one job run `type=job` covers
 * both the run's outcome (its timeline root) and everything it queued (its
 * children). Every lookup that treats the two as interchangeable gets this
 * wrong — see each test.
 */
class NestedJobDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_root_resolves_to_the_outcome_not_a_dispatch_it_made(): void
    {
        $runId = $this->seedJobRunThatDispatched(2);

        $root = app(TimelineStorage::class)->findByRequestId($runId, RecordType::Job->value);

        $this->assertNotNull($root);
        $this->assertSame('App\\Jobs\\Parent', $root->key);
        $this->assertSame('processed', $root->subtype);
    }

    public function test_job_timeline_keeps_the_dispatches_it_made(): void
    {
        $runId = $this->seedJobRunThatDispatched(3);

        $children = app(TimelineStorage::class)->timelineFor($runId, RecordType::Job->value);

        $dispatches = $children->where('type', RecordType::Job->value);

        $this->assertCount(3, $dispatches);
        $this->assertTrue($dispatches->every(fn (object $row) => $row->subtype === Jobs::DISPATCH));
    }

    public function test_job_timeline_still_excludes_the_runs_own_outcome(): void
    {
        $runId = $this->seedJobRunThatDispatched(1);

        $children = app(TimelineStorage::class)->timelineFor($runId, RecordType::Job->value);

        $this->assertCount(0, $children->where('subtype', 'processed'));
    }

    public function test_request_timeline_is_unaffected(): void
    {
        $requestId = (string) Str::uuid();

        DB::table('monitor_entries')->insert([
            $this->row('request', '2xx', 'GET /checkout', $requestId, ['status' => 200]),
            $this->row('job', Jobs::DISPATCH, 'App\\Jobs\\Child', $requestId, ['job_id' => 'j-1']),
            $this->row('query', null, 'select 1', $requestId, []),
        ]);

        $children = app(TimelineStorage::class)->timelineFor($requestId);

        $this->assertSame(['job', 'query'], $children->pluck('type')->sort()->values()->all());
    }

    /**
     * A job dispatched from inside a running job is an event *within* it, so
     * it belongs at the elapsed offset — not at zero, which is reserved for
     * the run's own outcome entry.
     */
    public function test_dispatch_inside_a_job_is_offset_from_the_runs_start(): void
    {
        Monitor::beginJobAttempt();
        usleep(20_000);
        Monitor::record(RecordType::Job, 'App\\Jobs\\Child', ['job_id' => 'j-1'], null, Jobs::DISPATCH);
        Monitor::record(RecordType::Job, 'App\\Jobs\\Parent', [], 25.0, 'processed');
        Monitor::endJobAttempt();
        Monitor::flush();

        $dispatch = DB::table('monitor_entries')->where('subtype', Jobs::DISPATCH)->first();
        $outcome = DB::table('monitor_entries')->where('subtype', 'processed')->first();

        $this->assertGreaterThan(0, (float) $dispatch->start_offset, 'the dispatch is not at the run root');
        $this->assertSame(0.0, (float) $outcome->start_offset, 'the outcome still anchors the track');
    }

    protected function seedJobRunThatDispatched(int $dispatches): string
    {
        $runId = (string) Str::uuid();
        $rows = [];

        for ($i = 0; $i < $dispatches; $i++) {
            $rows[] = $this->row('job', Jobs::DISPATCH, 'App\\Jobs\\Child', $runId, ['job_id' => "j-{$i}"]);
        }

        $rows[] = $this->row('query', null, 'select 1', $runId, []);
        // Last, so a lookup that ignores subtype picks a dispatch instead.
        $rows[] = $this->row('job', 'processed', 'App\\Jobs\\Parent', $runId, ['job_id' => 'parent'], 40.0);

        DB::table('monitor_entries')->insert($rows);

        return $runId;
    }

    /** @return array<string, mixed> */
    protected function row(
        string $type,
        ?string $subtype,
        string $key,
        string $requestId,
        array $payload,
        ?float $duration = null,
    ): array {
        return [
            'type' => $type,
            'subtype' => $subtype,
            'key' => $key,
            'payload' => json_encode($payload),
            'duration' => $duration,
            'request_id' => $requestId,
            'start_offset' => 0,
            'created_at' => now(),
        ];
    }
}
