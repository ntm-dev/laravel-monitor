<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Aggregator;

class AggregatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregator_rolls_raw_entries_up_into_fixed_count_buckets(): void
    {
        $now = CarbonImmutable::now();
        $bucketStart = $now->subMinutes(5)->setSeconds(0);

        $this->seedCheckpointBefore($bucketStart);
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(5));
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(30));
        $this->insertEntry('request', '4xx', $bucketStart->addSeconds(10));

        app(Aggregator::class)->run(period: 60);

        $this->assertDatabaseHas('monitor_aggregates', [
            'bucket' => $bucketStart->getTimestamp(),
            'period' => 60,
            'type' => 'request',
            'subtype' => '2xx',
            'aggregate' => 'count',
            'value' => 2,
        ]);

        $this->assertDatabaseHas('monitor_aggregates', [
            'bucket' => $bucketStart->getTimestamp(),
            'period' => 60,
            'type' => 'request',
            'subtype' => '4xx',
            'aggregate' => 'count',
            'value' => 1,
        ]);
    }

    public function test_counts_per_bucket_reads_aggregates_when_unfiltered(): void
    {
        $now = CarbonImmutable::now();
        $bucketStart = $now->subMinutes(5)->setSeconds(0);

        $this->seedCheckpointBefore($bucketStart);
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(5));
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(30));

        app(Aggregator::class)->run(period: 60);

        // Delete the raw rows so this can only pass if countsPerBucket() is
        // really reading monitor_aggregates, not silently falling back to a
        // raw scan of monitor_entries.
        DB::table('monitor_entries')->delete();

        // Query from $bucketStart, not further back: aggregatesCover() only
        // trusts monitor_aggregates once it actually has data at or before
        // the requested `since` — the aggregator here only ever produced a
        // bucket at $bucketStart (that's where the real 'request' entries
        // landed), so asking further back than that would correctly bail
        // out to the (now-empty) raw table instead of under-reporting.
        $storage = app(Storage::class);
        $counts = $storage->countsPerBucket('request', $bucketStart, 10, '2xx', null, $now);

        $this->assertSame(2, array_sum($counts));
    }

    public function test_stats_reads_aggregates_when_covered(): void
    {
        $now = CarbonImmutable::now();
        $bucketStart = $now->subMinutes(5)->setSeconds(0);

        $this->seedCheckpointBefore($bucketStart);
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(5), duration: 100.0);
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(30), duration: 300.0);

        app(Aggregator::class)->run(period: 60);

        // Delete the raw rows so this can only pass if stats() is really
        // reading monitor_aggregates, not silently falling back to a raw
        // scan of monitor_entries.
        DB::table('monitor_entries')->delete();

        $stats = app(Storage::class)->stats('request', $bucketStart, '2xx');

        $this->assertSame(2, $stats->count);
        $this->assertSame(200.0, $stats->avg_duration);
        $this->assertSame(300.0, $stats->max_duration);
        $this->assertSame(100.0, $stats->min_duration);
        $this->assertSame(400.0, $stats->total_duration);
    }

    public function test_stats_by_subtype_reads_aggregates_when_covered(): void
    {
        $now = CarbonImmutable::now();
        $bucketStart = $now->subMinutes(5)->setSeconds(0);

        $this->seedCheckpointBefore($bucketStart);
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(5), duration: 100.0);
        $this->insertEntry('request', '4xx', $bucketStart->addSeconds(10), duration: 50.0);

        app(Aggregator::class)->run(period: 60);

        DB::table('monitor_entries')->delete();

        $bySubtype = app(Storage::class)->statsBySubtype('request', $bucketStart);

        $this->assertSame(1, $bySubtype->get('2xx')->count);
        $this->assertSame(100.0, $bySubtype->get('2xx')->avg_duration);
        $this->assertSame(1, $bySubtype->get('4xx')->count);
        $this->assertNull($bySubtype->get('5xx'));
    }

    public function test_stats_falls_back_to_raw_scan_when_aggregates_do_not_cover_the_range(): void
    {
        $now = CarbonImmutable::now();
        $bucketStart = $now->subMinutes(5)->setSeconds(0);

        // The aggregator only ever produces a bucket at $bucketStart (that's
        // where the real 'request' entry lands) — asking stats() for a
        // window starting further back than that must not trust the
        // (incomplete) aggregates table and silently under-report; it has
        // to fall back to scanning raw rows instead.
        $this->seedCheckpointBefore($bucketStart);
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(5), duration: 100.0);

        app(Aggregator::class)->run(period: 60);

        // A second, older entry that predates aggregate coverage entirely —
        // only visible via the raw-scan fallback.
        $this->insertEntry('request', '2xx', $now->subMinutes(20), duration: 500.0);

        $stats = app(Storage::class)->stats('request', $now->subMinutes(30), '2xx');

        $this->assertSame(2, $stats->count);
    }

    public function test_counts_per_bucket_still_scans_raw_rows_when_filtered_by_key(): void
    {
        $now = CarbonImmutable::now();
        $bucketStart = $now->subMinutes(5)->setSeconds(0);

        // No aggregator run at all: a key-filtered query has no aggregate
        // breakdown to read, so it must still fall back to the raw scan.
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(5), 'GET /users');
        $this->insertEntry('request', '2xx', $bucketStart->addSeconds(10), 'GET /posts');

        $storage = app(Storage::class);
        $counts = $storage->countsPerBucket('request', $now->subMinutes(10), 10, '2xx', 'GET /users', $now);

        $this->assertSame(1, array_sum($counts));
    }

    protected function insertEntry(string $type, ?string $subtype, CarbonImmutable $createdAt, ?string $key = null, ?float $duration = null): void
    {
        DB::table('monitor_entries')->insert([
            'type' => $type,
            'subtype' => $subtype,
            'key' => $key,
            'payload' => '[]',
            'duration' => $duration,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Aggregator::run() only catches up from wherever monitor_aggregates last
     * left off (one period back on a cold start) — seed a checkpoint row
     * just before the window under test so a single run() call walks
     * forward through it deterministically, instead of depending on real
     * wall-clock timing to land the test data inside "the last completed
     * period".
     */
    protected function seedCheckpointBefore(CarbonImmutable $before): void
    {
        DB::table('monitor_aggregates')->insert([
            'bucket' => intdiv($before->getTimestamp(), 60) * 60 - 60,
            'period' => 60,
            'type' => '__seed__',
            'subtype' => '',
            'aggregate' => 'count',
            'value' => 0,
            'count' => 0,
        ]);
    }
}
