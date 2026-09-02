<?php

namespace LaravelMonitor\Storage\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * The monitor_aggregates fast-path: stats()/statsBySubtype()/countsPerBucket()
 * (in AggregatesStats) read pre-computed per-period totals from here instead
 * of scanning raw entries, whenever aggregatesCover() confirms the
 * `monitor:aggregate` command has actually been backfilling the requested
 * range.
 */
trait UsesAggregatesTable
{
    /**
     * Per-instance memo for aggregatesCover(), keyed by (type, since, until).
     * A single dashboard render asks the identical question several times
     * over — Requests.php alone calls it once via statsBySubtype() and four
     * more times via countsPerBucket() (2xx/3xx/4xx/5xx), all for the same
     * type/since/until — so without this, the bounds query plus the raw-table
     * existence check both run five times over for one page view. The
     * DatabaseAggregateStorage instance behind the AggregateStorage contract
     * is bound as a singleton (see MonitorServiceProvider::registerBindings()),
     * so this cache's lifetime matches a single request and never leaks
     * across requests. `$until === null` ("now") is
     * cached under a fixed sentinel rather than a literal timestamp: the goal
     * is reusing the answer for the rest of the same render, not
     * distinguishing between two calls a few milliseconds apart.
     *
     * @var array<string, bool>
     */
    protected array $aggregatesCoverCache = [];

    /**
     * Whether monitor_aggregates has been backfilling `$type` for the full
     * requested range — i.e. it's safe to trust for this range instead of
     * scanning the raw table. Without the lower-bound check,
     * stats()/statsBySubtype()/countsPerBucket() would silently report zero
     * for the (likely common) case of a fresh install that hasn't scheduled
     * `monitor:aggregate` yet, or one that only started recently and hasn't
     * caught up to cover the full requested window.
     *
     * The upper bound guards the opposite failure: once `monitor:aggregate`
     * stops running (missing schedule, crashed worker), old buckets stay in
     * place while new raw entries keep landing — a "last hour" query would
     * otherwise read back a confidently-wrong zero instead of falling back
     * to the raw scan. Rather than guess a staleness threshold (which would
     * either false-positive on a slow-but-healthy schedule or false-negative
     * on one that only just stalled), this checks for the one thing that
     * actually matters: whether anything relevant to the requested range has
     * been recorded since the aggregates' own last bucket. That's a single
     * indexed existence check against the raw table — a row lookup, not a
     * scan — so it stays cheap even though it isn't free; aggregatesCoverCache
     * above keeps it from repeating needlessly within the same render.
     */
    protected function aggregatesCover(string $type, DateTimeInterface $since, ?DateTimeInterface $until = null): bool
    {
        $cacheKey = $type.'|'.$this->toTimestamp($since).'|'.($until !== null ? $this->toTimestamp($until) : 'now');

        return $this->aggregatesCoverCache[$cacheKey] ??= $this->computeAggregatesCover($type, $since, $until);
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

        if ((int) $bounds->earliest > $this->toTimestamp($since)) {
            return false;
        }

        $requiredUpTo = $until !== null ? CarbonImmutable::parse($until) : CarbonImmutable::now();
        $latestBucketEnd = CarbonImmutable::createFromTimestamp((int) $bounds->latest);

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
     * Same shape as the raw-scan path above, but reads pre-computed per-
     * period counts from monitor_aggregates (written by the `monitor:aggregate`
     * command) instead of pulling every matching row's timestamp into PHP.
     * The aggregates table only carries type+subtype totals — no key/user
     * breakdown — so this only ever serves the unfiltered case; a route/job/
     * user-filtered chart falls back to the raw scan above. Only reached once
     * aggregatesCover() confirms the command has actually been backfilling
     * this type since before the requested range — buckets *within* an
     * otherwise-covered range that the aggregator hasn't reached yet (it's
     * still catching up, or missed a run) do simply read back as zero.
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
