<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

use function max;

/**
 * Rolls raw `monitor_entries` rows up into fixed-width `monitor_aggregates`
 * buckets — count, plus sum/max/min of `duration` — per type+subtype,
 * mirroring Laravel Pulse's ingest/rollup split: dashboard trend charts and
 * totals that don't need a route/user breakdown then read the much smaller
 * aggregates table instead of pulling every matching row into PHP, or
 * scanning every matching row for a SQL aggregate, on every page load (see
 * DatabaseStorage::countsPerBucket(), stats(), statsBySubtype()). Driven by
 * the `monitor:aggregate` command, expected to run about once per `period`
 * seconds — each run picks up from wherever the last one left off, so
 * occasional missed runs just get caught up on the next one (capped by
 * $maxBuckets so a long outage can't trigger an unbounded catch-up burst).
 *
 * sum/max/min are exactly mergeable across buckets (unlike a percentile),
 * so reassembling them into a stats()-shaped total for an arbitrary range
 * loses no precision — only avg needs deriving from sum/count rather than
 * being stored directly, since averaging averages isn't the same as
 * averaging the underlying values.
 */
class Aggregator
{
    public function __construct(protected DatabaseManager $db)
    {
    }

    public function run(int $period, int $maxBuckets = 500): int
    {
        $connection = $this->connection();
        $now = CarbonImmutable::now()->getTimestamp();

        $lastBucket = $this->aggregatesTable($connection)->max('bucket');
        $next = $lastBucket !== null ? $lastBucket + $period : $this->flooredBucket($now, $period) - $period;

        $processed = 0;

        while ($next + $period <= $now && $processed < $maxBuckets) {
            $this->aggregateBucket($connection, $next, $period);
            $next += $period;
            $processed++;
        }

        return $processed;
    }

    /**
     * Same forward walk as run(), then spends the rest of `$maxBuckets`
     * filling buckets before the earliest one already rolled up — newest
     * first, never further back than `$since` or the retention window — so
     * a range the schedule hasn't covered yet gets covered without waiting
     * for it. Returns the number of buckets processed.
     */
    public function catchUp(DateTimeInterface $since, int $period, int $maxBuckets = 30): int
    {
        $processed = $this->run($period, $maxBuckets);

        $connection = $this->connection();
        $now = CarbonImmutable::now()->getTimestamp();

        $earliest = $this->aggregatesTable($connection)->min('bucket');
        $cursor = ($earliest !== null ? (int) $earliest : $this->flooredBucket($now, $period) - $period) - $period;

        $floor = max(
            $this->flooredBucket($since->getTimestamp(), $period),
            $this->flooredBucket($now - (int) config('monitor.retention.hours', 168) * 3600, $period),
        );

        while ($cursor >= $floor && $processed < $maxBuckets) {
            $found = $this->aggregateBucket($connection, $cursor, $period);
            $processed++;

            if ($found) {
                $cursor -= $period;

                continue;
            }

            // An empty bucket leaves no row to anchor the next run on, so
            // jump straight to the newest entry before it.
            $newest = $this->newestEntryBefore($connection, StorageTime::fromTimestamp($cursor));

            if ($newest === null) {
                break;
            }

            $cursor = $this->flooredBucket($newest, $period);
        }

        return $processed;
    }

    protected function newestEntryBefore(ConnectionInterface $connection, CarbonImmutable $before): ?int
    {
        $newest = null;

        foreach (RecordType::cases() as $type) {
            $createdAt = $this->entriesTable($connection)
                ->where('type', $type->value)
                ->where('created_at', '<', $before)
                ->max('created_at');

            if ($createdAt !== null) {
                $newest = max($newest ?? 0, CarbonImmutable::parse($createdAt, config('app.timezone', 'UTC'))->getTimestamp());
            }
        }

        return $newest;
    }

    /** Whether the bucket held any entries. */
    protected function aggregateBucket(ConnectionInterface $connection, int $bucket, int $period): bool
    {
        $start = StorageTime::fromTimestamp($bucket);
        $end = StorageTime::fromTimestamp($bucket + $period);

        // One query per type: no index leads on created_at alone, so a
        // single `created_at` range without `type` scans the whole index.
        $rows = [];

        foreach (RecordType::cases() as $type) {
            $typeRows = $this->entriesTable($connection)
                ->select('subtype')
                ->selectRaw('count(*) as aggregate_count')
                // count(duration), not count(*): entries whose type never
                // carries a duration (e.g. cache misses before a value existed,
                // or a type that just doesn't track timing) shouldn't drag the
                // average down as if they were zero-duration.
                ->selectRaw('count(duration) as duration_count')
                ->selectRaw('sum(duration) as duration_sum')
                ->selectRaw('max(duration) as duration_max')
                ->selectRaw('min(duration) as duration_min')
                ->where('type', $type->value)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->groupBy('subtype')
                ->get();

            foreach ($typeRows as $row) {
                $row->type = $type->value;
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return false;
        }

        $aggregates = [];

        foreach ($rows as $row) {
            $subtype = $row->subtype ?? '';

            $aggregates[] = [
                'bucket' => $bucket,
                'period' => $period,
                'type' => $row->type,
                'subtype' => $subtype,
                'aggregate' => 'count',
                'value' => $row->aggregate_count,
                'count' => $row->aggregate_count,
            ];

            if ((int) $row->duration_count > 0) {
                foreach ([
                    'duration_sum' => $row->duration_sum,
                    'duration_max' => $row->duration_max,
                    'duration_min' => $row->duration_min,
                ] as $aggregate => $value) {
                    $aggregates[] = [
                        'bucket' => $bucket,
                        'period' => $period,
                        'type' => $row->type,
                        'subtype' => $subtype,
                        'aggregate' => $aggregate,
                        'value' => $value,
                        'count' => $row->duration_count,
                    ];
                }
            }
        }

        $this->aggregatesTable($connection)->upsert(
            $aggregates,
            ['bucket', 'period', 'type', 'subtype', 'aggregate'],
            ['value', 'count'],
        );

        return true;
    }

    protected function flooredBucket(int $timestamp, int $period): int
    {
        return intdiv($timestamp, $period) * $period;
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection(config('monitor.storage.database.connection'));
    }

    protected function entriesTable(ConnectionInterface $connection)
    {
        return $connection->table(config('monitor.storage.database.table', 'monitor_entries'));
    }

    protected function aggregatesTable(ConnectionInterface $connection)
    {
        return $connection->table(config('monitor.aggregates.table', 'monitor_aggregates'));
    }
}
