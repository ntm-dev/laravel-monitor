<?php

namespace LaravelMonitor\Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LaravelMonitor\Facades\Monitor;
use ReflectionClass;

/**
 * Where dispatch() was called, recorded on the 'queued' placeholder — the
 * only pointer back to the source for a job queued by another job, which
 * runs in a process with no other trace of its origin.
 */
class JobDispatchLocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Only that a call site is captured, as "file:line". *Which* frame it
     * picks can't be asserted here: Support\Location classifies vendor frames
     * against base_path(), which under Testbench is the skeleton app, so the
     * real vendor/ sitting beside this package never matches and the first
     * framework frame reads as application code. Recorders\Queries resolves
     * its own location through the exact same call and is right in a real app.
     */
    public function test_dispatching_a_job_records_a_call_site(): void
    {
        event($this->queuedEvent());
        Monitor::flush();

        $location = $this->queuedPayload()['location'] ?? null;

        $this->assertNotNull($location, 'the dispatch call site was not recorded');
        $this->assertMatchesRegularExpression('#^.+\.php:\d+$#', $location);
    }

    public function test_a_job_outcome_carries_no_call_site(): void
    {
        event($this->queuedEvent());
        Monitor::flush();

        $this->assertArrayNotHasKey('location', $this->outcomePayload());
    }

    /** Stored full-path, so the dashboard can show it without rebuilding it. */
    public function test_the_call_site_is_stored_as_an_absolute_path(): void
    {
        event($this->queuedEvent());
        Monitor::flush();

        $this->assertStringStartsWith(DIRECTORY_SEPARATOR, $this->queuedPayload()['location']);
    }

    public function test_the_job_detail_header_shows_where_the_job_was_queued_from(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        [$requestId, $outcomeId] = $this->seedRequestThatDispatched('/var/www/html/modules/Beauty/Jobs/SendMailBookingJob.php:46');

        $this->get("/monitor/requests/{$requestId}/{$outcomeId}")
            ->assertOk()
            ->assertSee('/var/www/html/modules/Beauty/Jobs/SendMailBookingJob.php:46');
    }

    /** @return array{0: string, 1: string} */
    protected function seedRequestThatDispatched(string $location): array
    {
        $requestId = (string) Str::uuid();
        $outcomeId = (string) Str::uuid();

        DB::table('monitor_entries')->insert([
            [
                'type' => 'request', 'subtype' => '2xx', 'key' => 'POST /reserve',
                'payload' => json_encode(['method' => 'POST', 'path' => '/reserve', 'status' => 200]),
                'duration' => 50, 'request_id' => $requestId, 'created_at' => now(),
            ],
            [
                'type' => 'job', 'subtype' => 'queued', 'key' => 'App\\Jobs\\SendMailBookingJob',
                'payload' => json_encode(['job_id' => 'job-hdr-1', 'location' => $location]),
                'duration' => null, 'request_id' => $requestId, 'created_at' => now(),
            ],
            [
                'type' => 'job', 'subtype' => 'processed', 'key' => 'App\\Jobs\\SendMailBookingJob',
                'payload' => json_encode(['job_id' => 'job-hdr-1', 'attempts' => 1]),
                'duration' => 30, 'request_id' => $outcomeId, 'created_at' => now(),
            ],
        ]);

        return [$requestId, $outcomeId];
    }

    protected function job(): SyncJob
    {
        return new SyncJob(new Container, $this->jobPayload(), 'sync', 'default');
    }

    protected function jobPayload(): string
    {
        return json_encode([
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => 'App\\Jobs\\SendWelcomeEmail', 'command' => 'x'],
            'displayName' => 'App\\Jobs\\SendWelcomeEmail',
            'uuid' => 'job-loc-1',
        ]);
    }

    protected function queuedEvent(): JobQueued
    {
        $args = [
            'connectionName' => 'sync',
            'queue' => 'default',
            'id' => 'job-loc-1',
            'job' => $this->job(),
            'payload' => $this->jobPayload(),
            'delay' => null,
        ];

        // JobQueued gained parameters across Laravel versions — same
        // compatibility dance as MonitorTest::constructEventCompatibly().
        $accepted = collect((new ReflectionClass(JobQueued::class))->getConstructor()->getParameters())->pluck('name');

        return new JobQueued(...collect($args)->only($accepted)->all());
    }

    /** @return array<string, mixed> */
    protected function outcomePayload(): array
    {
        event(new \Illuminate\Queue\Events\JobProcessing('sync', $this->job()));
        event(new \Illuminate\Queue\Events\JobProcessed('sync', $this->job()));
        Monitor::flush();

        return json_decode(
            DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'processed')->value('payload'),
            true,
        );
    }

    /** @return array<string, mixed> */
    protected function queuedPayload(): array
    {
        $row = DB::table('monitor_entries')
            ->where('type', 'job')
            ->where('subtype', 'queued')
            ->first();

        $this->assertNotNull($row, 'no queued job entry was recorded');

        return json_decode($row->payload, true);
    }
}
