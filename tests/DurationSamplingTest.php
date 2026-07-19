<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Storage\DatabaseStorage;

class DurationSamplingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * durationStats() caps how many raw rows it pulls into PHP to compute a
     * percentile (SQL has no portable one). The old implementation applied
     * that cap globally — "the N most recent rows overall" — which silently
     * drops an entire early bucket once a later, busier bucket alone has
     * enough rows to fill the whole cap. Subclassing to shrink the cap to 4
     * (2 per bucket, with buckets: 2) reproduces that at a handful of rows
     * instead of needing to actually insert tens of thousands.
     */
    public function test_duration_stats_samples_every_bucket_instead_of_only_the_most_recent_rows(): void
    {
        $now = CarbonImmutable::now();
        $since = $now->subMinutes(20);

        // Older bucket: only 2 rows — well under the cap on its own.
        $this->insertRequest($now->subMinutes(18), 100.0);
        $this->insertRequest($now->subMinutes(15), 200.0);

        // Recent bucket: 5 rows — more than the entire global cap (4) by
        // itself. Under the old "most recent N overall" behavior, these
        // alone would fill every slot and the older bucket above would be
        // read back as having recorded nothing at all.
        $this->insertRequest($now->subMinutes(9), 10.0);
        $this->insertRequest($now->subMinutes(8), 20.0);
        $this->insertRequest($now->subMinutes(7), 30.0);
        $this->insertRequest($now->subMinutes(6), 40.0);
        $this->insertRequest($now->subMinutes(5), 50.0);

        $storage = new class(app('db')) extends DatabaseStorage
        {
            protected function maxSampleRows(): int
            {
                return 4;
            }
        };

        $stats = $storage->durationStats('request', $since, buckets: 2);

        $this->assertNotNull($stats->avg_per_bucket[0], 'the older bucket must not be starved out by the busier recent one');
        $this->assertSame(150.0, $stats->avg_per_bucket[0]);
        $this->assertNotNull($stats->p95_per_bucket[0]);

        $this->assertNotNull($stats->avg_per_bucket[1]);
    }

    protected function insertRequest(CarbonImmutable $createdAt, float $duration): void
    {
        DB::table('monitor_entries')->insert([
            'type' => 'request',
            'subtype' => '2xx',
            'key' => 'GET /users',
            'payload' => '[]',
            'duration' => $duration,
            'created_at' => $createdAt,
        ]);
    }
}
