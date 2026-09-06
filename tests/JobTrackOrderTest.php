<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A request's job tracks are ordered by when each job actually ran, not by
 * the order it dispatched them. Every track's bar is already positioned on
 * the root's own clock via its 'start', so dispatch order let a bar that
 * starts later sit above one that starts earlier — routine with queues,
 * where two jobs dispatched back to back can be picked up in either order.
 */
class JobTrackOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_tracks_are_ordered_by_execution_start_not_dispatch_order(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $requestStart = CarbonImmutable::parse('2024-01-01 00:00:00');

        // 'early' is dispatched FIRST but runs SECOND — the shape that made
        // the bug visible (see the real trace this test was written from).
        [$requestId, $ids] = $this->seedRequestDispatchingJobs($requestStart, [
            'early-dispatch-late-run' => ['queuedAt' => 0.100, 'startedAt' => 1.800, 'duration' => 72.85],
            'late-dispatch-early-run' => ['queuedAt' => 0.200, 'startedAt' => 1.480, 'duration' => 212.079],
        ]);

        $tracks = $this->get("/monitor/requests/{$requestId}")->assertOk()->viewData('tracks');

        $this->assertSame('root', $tracks[0]['id'], 'the root track stays first');

        $jobTracks = array_slice($tracks, 1);
        $starts = array_column($jobTracks, 'start');

        $this->assertCount(2, $jobTracks);
        $this->assertLessThan($starts[1], $starts[0], 'tracks run earliest-first');
        $this->assertSame(
            [$ids['late-dispatch-early-run'], $ids['early-dispatch-late-run']],
            array_column($jobTracks, 'label'),
        );
    }

    public function test_jobs_that_started_together_keep_their_dispatch_order(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $requestStart = CarbonImmutable::parse('2024-01-01 00:00:00');

        [$requestId, $ids] = $this->seedRequestDispatchingJobs($requestStart, [
            'dispatched-first' => ['queuedAt' => 0.100, 'startedAt' => 1.500, 'duration' => 10.0],
            'dispatched-second' => ['queuedAt' => 0.200, 'startedAt' => 1.500, 'duration' => 10.0],
        ]);

        $tracks = $this->get("/monitor/requests/{$requestId}")->assertOk()->viewData('tracks');

        $this->assertSame(
            [$ids['dispatched-first'], $ids['dispatched-second']],
            array_column(array_slice($tracks, 1), 'label'),
        );
    }

    /**
     * One request plus, per entry in $jobs, a 'queued' placeholder on the
     * request and a 'processed' outcome under its own request_id — the real
     * two-request_id shape (see MonitorTest::seedRequestThatDispatchedAJob()).
     * Offsets in $jobs are seconds from $requestStart; 'duration' is in ms.
     *
     * @param  array<string, array{queuedAt: float, startedAt: float, duration: float}>  $jobs
     * @return array{0: string, 1: array<string, string>}
     */
    /**
     * A mailable queued by a job queued by the request — two levels down, so
     * it only ever showed as a dead-end "JOB DISPATCH" marker before.
     */
    public function test_a_job_dispatched_by_a_dispatched_job_gets_its_own_track(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $requestStart = CarbonImmutable::parse('2024-01-01 00:00:00');

        [$requestId, $ids] = $this->seedRequestDispatchingJobs($requestStart, [
            'App\\Jobs\\SendMailBookingJob' => ['queuedAt' => 0.100, 'startedAt' => 1.000, 'duration' => 60.0],
        ]);

        // Queued from inside the job's own run, under the job's request_id.
        $this->seedNestedDispatch(
            dispatcherRunId: $this->outcomeRequestIdFor('App\\Jobs\\SendMailBookingJob'),
            requestStart: $requestStart,
            label: 'App\\Mail\\SendMailBooking',
            startedAt: 1.500,
            duration: 25.0,
        );

        $tracks = $this->get("/monitor/requests/{$requestId}")->assertOk()->viewData('tracks');
        $labels = array_column(array_slice($tracks, 1), 'label');

        $this->assertSame([$ids['App\\Jobs\\SendMailBookingJob'], 'App\\Mail\\SendMailBooking'], $labels);
    }

    protected function outcomeRequestIdFor(string $label): string
    {
        return DB::table('monitor_entries')
            ->where('key', $label)
            ->where('subtype', 'processed')
            ->value('request_id');
    }

    protected function seedNestedDispatch(
        string $dispatcherRunId,
        CarbonImmutable $requestStart,
        string $label,
        float $startedAt,
        float $duration,
    ): void {
        $jobId = 'job-'.md5($label);

        DB::table('monitor_entries')->insert([
            [
                'type' => 'job',
                'subtype' => 'queued',
                'key' => $label,
                'payload' => json_encode(['connection' => 'redis', 'queue' => 'default', 'job_id' => $jobId]),
                'duration' => null,
                'request_id' => $dispatcherRunId,
                'created_at' => $requestStart->addMilliseconds(1020)->format('Y-m-d H:i:s.u'),
            ],
            [
                'type' => 'job',
                'subtype' => 'processed',
                'key' => $label,
                'payload' => json_encode([
                    'connection' => 'redis',
                    'queue' => 'default',
                    'job_id' => $jobId,
                    'attempts' => 1,
                    'started_at' => (float) $requestStart->addMilliseconds((int) ($startedAt * 1000))->format('U.u'),
                ]),
                'duration' => $duration,
                'request_id' => (string) Str::uuid(),
                'created_at' => $requestStart
                    ->addMilliseconds((int) ($startedAt * 1000 + $duration))
                    ->format('Y-m-d H:i:s.u'),
            ],
        ]);
    }

    protected function seedRequestDispatchingJobs(CarbonImmutable $requestStart, array $jobs): array
    {
        $requestId = (string) Str::uuid();
        $requestDuration = 2000.0;

        $rows = [[
            'type' => 'request',
            'subtype' => '2xx',
            'key' => 'POST /reserve/create',
            'payload' => json_encode([
                'method' => 'POST',
                'path' => '/reserve/create',
                'status' => 200,
                'started_at' => (float) $requestStart->format('U.u'),
            ]),
            'duration' => $requestDuration,
            'request_id' => $requestId,
            // Stamped at the request's END, same as Recorders\Requests.
            'created_at' => $requestStart->addMilliseconds((int) $requestDuration)->format('Y-m-d H:i:s.u'),
        ]];

        $labels = [];

        foreach ($jobs as $label => $timing) {
            $jobId = 'job-'.md5($label);
            $labels[$label] = $label;
            $startedAt = (float) $requestStart->addMilliseconds((int) ($timing['startedAt'] * 1000))->format('U.u');

            $rows[] = [
                'type' => 'job',
                'subtype' => 'queued',
                'key' => $label,
                'payload' => json_encode(['connection' => 'database', 'queue' => 'default', 'job_id' => $jobId]),
                'duration' => null,
                'request_id' => $requestId,
                'created_at' => $requestStart->addMilliseconds((int) ($timing['queuedAt'] * 1000))->format('Y-m-d H:i:s.u'),
            ];

            $rows[] = [
                'type' => 'job',
                'subtype' => 'processed',
                'key' => $label,
                'payload' => json_encode([
                    'connection' => 'database',
                    'queue' => 'default',
                    'job_id' => $jobId,
                    'attempts' => 1,
                    'started_at' => $startedAt,
                ]),
                'duration' => $timing['duration'],
                'request_id' => (string) Str::uuid(),
                'created_at' => $requestStart
                    ->addMilliseconds((int) ($timing['startedAt'] * 1000 + $timing['duration']))
                    ->format('Y-m-d H:i:s.u'),
            ];
        }

        DB::table('monitor_entries')->insert($rows);

        return [$requestId, $labels];
    }
}
