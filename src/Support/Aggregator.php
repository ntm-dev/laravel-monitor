<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

use function max;

/**
 * Rolls `monitor_entries` up into fixed-width `monitor_aggregates` buckets (count,
 * plus sum/max/min of `duration`) per type+subtype, read by DatabaseAggregateStorage.
 * Each `monitor:aggregate` run resumes after the last stored bucket, up to $maxBuckets.
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
     * run(), then spends the rest of `$maxBuckets` backfilling before the earliest
     * stored bucket, newest first, down to `$since` or the retention window.
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

            // Empty bucket: jump straight to the newest entry before it.
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

        // One query per type: no index leads on created_at alone.
        $rows = [];

        foreach (RecordType::cases() as $type) {
            $typeRows = $this->entriesTable($connection)
                ->select('subtype')
                ->selectRaw('count(*) as aggregate_count')
                // Rows without a duration must not count as zero in the average.
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
