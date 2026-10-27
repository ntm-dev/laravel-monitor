<?php

namespace LaravelMonitor\Storage\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LaravelMonitor\Support\AggregateCatchUp;
use LaravelMonitor\Support\StorageTime;

/**
 * monitor_aggregates fast path for stats()/statsBySubtype()/countsPerBucket(),
 * used only when aggregatesCover() confirms the requested range is fully aggregated.
 */
trait UsesAggregatesTable
{
    /**
     * Memo for aggregatesCover() keyed by type|since|until ('now' when open-ended).
     * One render asks the same question several times; the storage is a singleton,
     * so this lives for one request.
     *
     * @var array<string, bool>
     */
    protected array $aggregatesCoverCache = [];

    /**
     * Whether monitor_aggregates can stand in for raw entries over the range: no raw rows
     * of $type before its first bucket (within the range) or after its last bucket.
     * On false, defers an aggregation catch-up via AggregateCatchUp.
     */
    protected function aggregatesCover(string $type, DateTimeInterface $since, ?DateTimeInterface $until = null): bool
    {
        $cacheKey = $type.'|'.$this->toTimestamp($since).'|'.($until !== null ? $this->toTimestamp($until) : 'now');

        if (! isset($this->aggregatesCoverCache[$cacheKey])) {
            $this->aggregatesCoverCache[$cacheKey] = $this->computeAggregatesCover($type, $since, $until);

            if (! $this->aggregatesCoverCache[$cacheKey]) {
                app(AggregateCatchUp::class)->defer($since);
            }
        }

        return $this->aggregatesCoverCache[$cacheKey];
    }

    protected function computeAggregatesCover(string $type, DateTimeInterface $since, ?DateTimeInterface $until): bool
    {
        $bounds = $this->aggregatesTable()
            ->where('type', $type)
            ->selectRaw('min(bucket) as earliest, max(bucket) as latest')
            ->first();

        if ($bounds === null || $bounds->earliest === null) {
            return false;
        }

        // A first bucket after `since` is only a gap if raw rows exist before it.
        if ((int) $bounds->earliest > $this->toTimestamp($since) && $this->table()
            ->where('type', $type)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', StorageTime::fromTimestamp((int) $bounds->earliest))
            ->exists()) {
            return false;
        }

        $requiredUpTo = $until !== null ? CarbonImmutable::parse($until) : CarbonImmutable::now();
        $latestBucketEnd = StorageTime::fromTimestamp((int) $bounds->latest);

        if ($requiredUpTo->lessThanOrEqualTo($latestBucketEnd)) {
            return true;
        }

        return ! $this->table()
            ->where('type', $type)
            ->where('created_at', '>', $latestBucketEnd)
            ->where('created_at', '<=', $requiredUpTo)
            ->exists();
    }

    protected function statsFromAggregates(string $type, ?string $subtype, DateTimeInterface $since, ?DateTimeInterface $until): object
    {
        $rows = $this->aggregatesTable()
            ->where('type', $type)
            ->when($subtype !== null, fn (Builder $q) => $q->where('subtype', $subtype))
            ->where('bucket', '>=', $this->toTimestamp($since))
            ->where('bucket', '<', $until !== null ? $this->toTimestamp($until) : CarbonImmutable::now()->getTimestamp())
            ->whereIn('aggregate', ['count', 'duration_sum', 'duration_max', 'duration_min'])
            ->select('aggregate')
            ->selectRaw('sum(value) as total_value, sum(count) as total_count, max(value) as max_value, min(value) as min_value')
            ->groupBy('aggregate')
            ->get()
            ->keyBy('aggregate');

        return $this->assembleStatsFromAggregateRows($rows);
    }

    protected function statsBySubtypeFromAggregates(string $type, DateTimeInterface $since, ?DateTimeInterface $until): Collection
    {
        return $this->aggregatesTable()
            ->where('type', $type)
            ->where('subtype', '!=', '')
            ->where('bucket', '>=', $this->toTimestamp($since))
            ->where('bucket', '<', $until !== null ? $this->toTimestamp($until) : CarbonImmutable::now()->getTimestamp())
            ->whereIn('aggregate', ['count', 'duration_sum', 'duration_max', 'duration_min'])
            ->select('subtype', 'aggregate')
            ->selectRaw('sum(value) as total_value, sum(count) as total_count, max(value) as max_value, min(value) as min_value')
            ->groupBy('subtype', 'aggregate')
            ->get()
            ->groupBy('subtype')
            ->map(fn (Collection $rows) => $this->assembleStatsFromAggregateRows($rows->keyBy('aggregate')));
    }

    /**
     * @param  Collection<string, object>  $rows  keyed by aggregate name
     *                                             (count/duration_sum/duration_max/duration_min)
     */
    protected function assembleStatsFromAggregateRows(Collection $rows): object
    {
        $count = (int) ($rows->get('count')->total_value ?? 0);
        $durationSum = $rows->get('duration_sum');
        $totalDuration = $durationSum?->total_value;
        $durationCount = (int) ($durationSum?->total_count ?? 0);

        return (object) [
            'count' => $count,
            'avg_duration' => $durationCount > 0 ? round(((float) $totalDuration) / $durationCount, 2) : null,
            'max_duration' => isset($rows->get('duration_max')->max_value) ? (float) $rows->get('duration_max')->max_value : null,
            'min_duration' => isset($rows->get('duration_min')->min_value) ? (float) $rows->get('duration_min')->min_value : null,
            'total_duration' => $totalDuration !== null ? round((float) $totalDuration, 2) : null,
        ];
    }

    /**
     * countsPerBucket() from monitor_aggregates. The table holds only type+subtype
     * totals, so key/user-filtered calls must use the raw scan instead.
     */
    protected function countsPerBucketFromAggregates(string $type, ?string $subtype, CarbonImmutable $start, float $bucketSize, int $buckets): array
    {
        $counts = array_fill(0, $buckets, 0);
        $startTimestamp = $start->getTimestamp();
        $endTimestamp = $startTimestamp + (int) ceil($bucketSize * $buckets);

        $this->aggregatesTable()
            ->where('type', $type)
            ->where('subtype', $subtype ?? '')
            ->where('aggregate', 'count')
            ->where('bucket', '>=', $startTimestamp)
            ->where('bucket', '<', $endTimestamp)
            ->get(['bucket', 'value'])
            ->each(function ($row) use (&$counts, $startTimestamp, $bucketSize, $buckets) {
                $index = min($buckets - 1, max(0, (int) floor(($row->bucket - $startTimestamp) / $bucketSize)));
                $counts[$index] += (int) $row->value;
            });

        return $counts;
    }
}
