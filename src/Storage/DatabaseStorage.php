<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Format;
use Ramsey\Uuid\Uuid;

class DatabaseStorage implements Storage
{
    /**
     * Cap applied two ways, both driven by the same 10M-row benchmark:
     *
     * - routeStats(), durationStats(), queryStats(), exceptionGroups() pull
     *   raw rows into PHP to compute a percentile or group there (SQL has no
     *   portable, driver-agnostic percentile function). Left unbounded, a
     *   busy app's "last 24h" view can match millions of rows and exhaust
     *   PHP's memory limit outright rather than just running slow.
     * - aggregateByKey(), cacheKeyStats() GROUP BY key in SQL, which doesn't
     *   need PHP memory but isn't free either: MySQL sometimes picks a
     *   key-ordered index to avoid a sort for the GROUP BY, which means
     *   every matching row needs a lookup just to check the date filter —
     *   40x+ slower than the equivalent covering-index scan once the filter
     *   only matches a fraction of the table. Wrapping the filtered rows in
     *   a LIMITed subquery before the GROUP BY bounds that cost regardless
     *   of which index MySQL ends up choosing.
     *
     * Every capped query orders by id DESC first, so the sample is "most
     * recent N rows", not an arbitrary slice. stats() is the one aggregate
     * left uncapped: it reports a single exact total, not a per-group
     * breakdown, and the covering index alone keeps it fast without needing
     * to sacrifice exactness.
     */
    protected const MAX_SAMPLE_ROWS = 50000;

    /**
     * A method, not a bare reference to the constant, so tests can subclass
     * DatabaseStorage and shrink this to reproduce cap-related sampling
     * behavior (e.g. an early bucket losing all representation once total
     * volume exceeds the cap) without needing to actually insert tens of
     * thousands of rows.
     */
    protected function maxSampleRows(): int
    {
        return self::MAX_SAMPLE_ROWS;
    }

    public function __construct(
        protected DatabaseManager $db,
        protected array $config = [],
    ) {
    }

    public function store(array $entries): void
    {
        collect($entries)
            ->map(function ($entry) {
                $row = $entry->toArray();
                $row['payload'] = json_encode($row['payload']);
                $row['created_at'] = $row['created_at']->toDateTimeString();

                return $row;
            })
            ->chunk(100)
            ->each(fn (Collection $chunk) => $this->table()->insert($chunk->all()));
    }

    public function purge(?DateTimeInterface $before = null): int
    {
        $query = $this->table();

        if ($before !== null) {
            $query->where('created_at', '<', $before);
        }

        return $query->delete();
    }

    public function recent(
        string $type,
        DateTimeInterface $since,
        int $limit = 50,
        ?string $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
    ): Collection {
        return $this->query($type, $since, $subtype, $key, $until)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->hydrate($row));
    }

    public function findByRequestId(string $requestId, string $rootType = 'request'): ?object
    {
        $row = $this->table()
            ->where('type', $rootType)
            ->where('request_id', $requestId)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findById(int $id, string $type): ?object
    {
        $row = $this->table()
            ->where('type', $type)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findByCorrelationId(string $type, string $correlationId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object
    {
        // Narrowed by the existing [type, created_at] index before the JSON
        // lookup — a correlated pair always lands moments apart, so callers
        // pass a tight since/until around the source entry rather than the
        // dashboard's full selected range.
        $row = $this->table()
            ->where('type', $type)
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->where('payload->correlation_id', $correlationId)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function timelineFor(string $requestId, string $rootType = 'request'): Collection
    {
        return $this->table()
            ->where('request_id', $requestId)
            ->where('type', '!=', $rootType)
            ->orderBy('start_offset')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->hydrate($row));
    }

    public function cacheKeyStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection
    {
        // GROUP BY key over the raw table, not the sampled subquery below:
        // MySQL sees an index it can walk in key order (idx_type_key) and
        // prefers that over a covering index that's actually filtered by
        // created_at first, to avoid a sort step for the GROUP BY — cheap
        // when the date filter matches most of the table, ruinous when it
        // doesn't, since every matching row then needs a lookup just to
        // check created_at. Capping the input via a LIMITed subquery (same
        // sampling as routeStats() et al.) keeps the group-by's input
        // bounded regardless of which index MySQL picks for it. Ordered by
        // created_at, not id: id isn't part of any index here, so sorting
        // by it forces a full sort of every matching row before the LIMIT
        // can apply — created_at is the index's leading range column, so
        // MySQL can walk it backwards and stop at the limit instead
        // (measured 85x faster). Ties on the same created_at second come
        // back in whatever order the storage engine hands them over, which
        // is fine — nothing here depends on their relative order.
        $sample = $this->table()
            ->where('type', 'cache')
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->select(['key', 'subtype'])
            ->orderByDesc('created_at')
            ->limit($this->maxSampleRows());

        return $this->table()
            ->fromSub($sample, 't')
            ->select('key')
            ->selectRaw("sum(case when subtype = 'hit' then 1 else 0 end) as hits")
            ->selectRaw("sum(case when subtype = 'miss' then 1 else 0 end) as misses")
            ->selectRaw("sum(case when subtype = 'write' then 1 else 0 end) as writes")
            ->selectRaw("sum(case when subtype = 'forget' then 1 else 0 end) as deletes")
            ->selectRaw("sum(case when subtype in ('write_failed', 'forget_failed') then 1 else 0 end) as failures")
            ->selectRaw('count(*) as aggregate_count')
            ->groupBy('key')
            ->get()
            ->map(function ($row) {
                $hits = (int) $row->hits;
                $misses = (int) $row->misses;

                return (object) [
                    'key' => $row->key,
                    'hit_ratio' => ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100) : null,
                    'hits' => $hits,
                    'misses' => $misses,
                    'writes' => (int) $row->writes,
                    'deletes' => (int) $row->deletes,
                    'failures' => (int) $row->failures,
                    'total' => (int) $row->aggregate_count,
                ];
            });
    }

    public function queryStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection
    {
        $rows = $this->table()
            ->where('type', 'slow_query')
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            // created_at, not id, as the primary sort — see cacheKeyStats()
            // for why — but this sample's duration values feed avg/p95
            // directly (unlike cacheKeyStats' count-only aggregates), so a
            // tied created_at second needs a deterministic secondary sort
            // too — see sampleDurationsAcrossBuckets() for why.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            ->get(['key', 'duration', 'payload']);

        // Single foreach pass with plain arrays — see routeStats() for why
        // a map()+groupBy()+map() chain isn't worth it at the sample cap.
        $groups = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload ?? '[]', true) ?: [];
            $connection = $payload['connection'] ?? 'default';
            $groupKey = $row->key.'@@'.$connection;

            $group = &$groups[$groupKey];
            $group ??= ['key' => (string) $row->key, 'connection' => $connection, 'calls' => 0, 'durations' => []];

            $group['calls']++;

            if ($row->duration !== null) {
                $group['durations'][] = (float) $row->duration;
            }
            unset($group);
        }

        $result = [];

        foreach ($groups as $group) {
            $durations = $group['durations'];

            $result[] = (object) [
                'key' => $group['key'],
                'connection' => $group['connection'],
                'calls' => $group['calls'],
                'total' => round(array_sum($durations), 2),
                'avg' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                'p95' => $this->percentile($durations, 0.95),
            ];
        }

        return collect($result);
    }

    public function requestLabels(array $requestIds): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        // The `request` entry's key is already stored as "METHOD /path"
        // (see Recorders\Requests) — no need to decode payload for this.
        return $this->table()
            ->where('type', 'request')
            ->whereIn('request_id', $requestIds)
            ->pluck('key', 'request_id');
    }

    public function rootTypesFor(array $requestIds): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        return $this->table()
            ->whereIn('type', ['request', 'job'])
            ->whereIn('request_id', $requestIds)
            ->pluck('type', 'request_id');
    }

    /** Decode the JSON payload and parse timestamps on a raw row. */
    protected function hydrate(object $row): object
    {
        $row->payload = json_decode($row->payload ?? '[]', true) ?: [];
        $row->created_at = CarbonImmutable::parse($row->created_at);

        return $row;
    }

    public function aggregateByKey(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        int $limit = 10,
        string $orderBy = 'count',
        ?DateTimeInterface $until = null,
    ): Collection {
        if (! in_array($orderBy, ['count', 'avg_duration', 'max_duration', 'last_seen'], true)) {
            $orderBy = 'count';
        }

        $orderColumn = $orderBy === 'count' ? 'aggregate_count' : $orderBy;

        // See cacheKeyStats() for why the GROUP BY runs over a capped
        // subquery, ordered by created_at rather than id, rather than the
        // raw filtered table directly. Unlike cacheKeyStats' count-only
        // aggregates, avg_duration/max_duration below are computed from
        // this sample's actual values, so created_at alone isn't a safe
        // sort within a tied second — see sampleDurationsAcrossBuckets()
        // for why `id` is added as a deterministic tiebreaker.
        $sample = $this->query($type, $since, $subtype, null, $until)
            ->select(['key', 'duration', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows());

        return $this->table()
            ->fromSub($sample, 't')
            ->select('key')
            ->selectRaw('count(*) as aggregate_count, avg(duration) as avg_duration, max(duration) as max_duration, max(created_at) as last_seen')
            ->groupBy('key')
            ->orderByDesc($orderColumn)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $row->count = (int) $row->aggregate_count;
                unset($row->aggregate_count);
                $row->avg_duration = $row->avg_duration !== null ? round((float) $row->avg_duration, 2) : null;
                $row->max_duration = $row->max_duration !== null ? (float) $row->max_duration : null;
                $row->last_seen = $row->last_seen !== null ? CarbonImmutable::parse($row->last_seen) : null;

                return $row;
            });
    }

    public function stats(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): object {
        if ($key === null && $userId === null && $this->aggregatesCover($type, $since, $until)) {
            return $this->statsFromAggregates($type, $subtype, $since, $until);
        }

        $row = $this->query($type, $since, $subtype, $key, $until, $userId)
            ->selectRaw('count(*) as aggregate_count, avg(duration) as avg_duration, max(duration) as max_duration, min(duration) as min_duration, sum(duration) as total_duration')
            ->first();

        return (object) [
            'count' => (int) ($row->aggregate_count ?? 0),
            'avg_duration' => isset($row->avg_duration) ? round((float) $row->avg_duration, 2) : null,
            'max_duration' => isset($row->max_duration) ? (float) $row->max_duration : null,
            'min_duration' => isset($row->min_duration) ? (float) $row->min_duration : null,
            'total_duration' => isset($row->total_duration) ? round((float) $row->total_duration, 2) : null,
        ];
    }

    public function statsBySubtype(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
        ?string $key = null,
    ): Collection {
        if ($key === null && $userId === null && $this->aggregatesCover($type, $since, $until)) {
            return $this->statsBySubtypeFromAggregates($type, $since, $until);
        }

        return $this->query($type, $since, null, $key, $until, $userId)
            ->select('subtype')
            ->selectRaw('count(*) as aggregate_count, avg(duration) as avg_duration, max(duration) as max_duration, min(duration) as min_duration, sum(duration) as total_duration')
            ->groupBy('subtype')
            ->get()
            ->filter(fn ($row) => $row->subtype !== null)
            ->keyBy('subtype')
            ->map(fn ($row) => (object) [
                'count' => (int) $row->aggregate_count,
                'avg_duration' => isset($row->avg_duration) ? round((float) $row->avg_duration, 2) : null,
                'max_duration' => isset($row->max_duration) ? (float) $row->max_duration : null,
                'min_duration' => isset($row->min_duration) ? (float) $row->min_duration : null,
                'total_duration' => isset($row->total_duration) ? round((float) $row->total_duration, 2) : null,
            ]);
    }

    /**
     * Per-instance memo for aggregatesCover(), keyed by (type, since, until).
     * A single dashboard render asks the identical question several times
     * over — Requests.php alone calls it once via statsBySubtype() and four
     * more times via countsPerBucket() (2xx/3xx/4xx/5xx), all for the same
     * type/since/until — so without this, the bounds query plus the raw-table
     * existence check both run five times over for one page view. The
     * DatabaseStorage instance behind the Storage facade is resolved once per
     * request (StorageManager, its owner, is a singleton, and Manager caches
     * driver instances by name), so this cache's lifetime matches a single
     * request and never leaks across requests. `$until === null` ("now") is
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

    /** Unix timestamp for a DateTimeInterface, matching the bucket column's storage unit. */
    protected function toTimestamp(DateTimeInterface $date): int
    {
        return ($date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date->format('Y-m-d H:i:s')))->getTimestamp();
    }

    public function countsPerBucket(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): array {
        [$start, $bucketSize] = $this->bucketGrid($since, $buckets, $until);

        if ($key === null && $userId === null && $this->aggregatesCover($type, $since, $until)) {
            return $this->countsPerBucketFromAggregates($type, $subtype, $start, $bucketSize, $buckets);
        }

        $counts = array_fill(0, $buckets, 0);

        // Capped and ordered by created_at — see cacheKeyStats() for why —
        // same as every other raw-scan fallback in this class. This one
        // was missed when the others were capped: a range aggregatesCover()
        // correctly declines to serve (not yet backfilled, or filtered by
        // key/user) falls all the way back to this pluck(), which is
        // otherwise unbounded and will exhaust PHP's memory limit outright
        // on a wide enough window — measured directly on a 7-day range.
        $this->query($type, $since, $subtype, $key, $until, $userId)
            ->orderByDesc('created_at')
            ->limit($this->maxSampleRows())
            ->pluck('created_at')
            ->each(function ($createdAt) use (&$counts, $start, $bucketSize, $buckets) {
                $counts[$this->bucketIndex($createdAt, $start, $bucketSize, $buckets)]++;
            });

        return $counts;
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

    public function durationStats(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $key = null,
        ?string $subtype = null,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): object {
        [$start, $bucketSize] = $this->bucketGrid($since, $buckets, $until);

        $perBucket = array_fill(0, $buckets, []);
        $all = [];

        $this->sampleDurationsAcrossBuckets($type, $subtype, $key, $userId, $until, $start, $bucketSize, $buckets)
            ->each(function ($row) use (&$perBucket, &$all, $start, $bucketSize, $buckets) {
                $duration = (float) $row->duration;
                $all[] = $duration;
                $perBucket[$this->bucketIndex($row->created_at, $start, $bucketSize, $buckets)][] = $duration;
            });

        return (object) [
            'min' => $all === [] ? null : min($all),
            'max' => $all === [] ? null : max($all),
            'avg' => $all === [] ? null : round(array_sum($all) / count($all), 2),
            'p95' => $this->percentile($all, 0.95),
            'avg_per_bucket' => array_map(
                fn (array $values) => $values === [] ? null : round(array_sum($values) / count($values), 2),
                $perBucket,
            ),
            'p95_per_bucket' => array_map(
                fn (array $values) => $this->percentile($values, 0.95),
                $perBucket,
            ),
        ];
    }

    /**
     * Samples up to MAX_SAMPLE_ROWS / $buckets rows per bucket instead of the
     * most recent MAX_SAMPLE_ROWS overall. A flat "most recent N" cap starves
     * the earlier buckets once total volume for the range exceeds the cap —
     * e.g. a busy install with 250k requests in a 24h window would only ever
     * see duration data for roughly the last 19 of those 24 hours, while the
     * request-count chart (backed by monitor_aggregates, which isn't capped
     * by row count) kept showing real traffic throughout, making the gap look
     * like missing data rather than a sampling artifact.
     *
     * One SQL statement — a UNION ALL of one capped, ordered subquery per
     * bucket — not one round trip per bucket. The last bucket has no
     * exclusive upper bound of its own (it instead reuses the same `$until`
     * constraint the other methods apply): its computed boundary is derived
     * from float division and could, by a fraction of a second, fall short
     * of the actual end of the range and silently drop the most recent row.
     */
    protected function sampleDurationsAcrossBuckets(
        string $type,
        ?string $subtype,
        ?string $key,
        ?int $userId,
        ?DateTimeInterface $until,
        CarbonImmutable $start,
        float $bucketSize,
        int $buckets,
    ): Collection {
        $capPerBucket = max(1, intdiv($this->maxSampleRows(), $buckets));
        $startTimestamp = $start->getTimestamp();

        $subqueries = [];

        for ($i = 0; $i < $buckets; $i++) {
            $isLastBucket = $i === $buckets - 1;
            $bucketStart = CarbonImmutable::createFromTimestamp($startTimestamp + (int) round($i * $bucketSize));
            $bucketEnd = $isLastBucket
                ? null
                : CarbonImmutable::createFromTimestamp($startTimestamp + (int) round(($i + 1) * $bucketSize));

            $subqueries[] = $this->table()
                ->where('type', $type)
                ->when($subtype !== null, fn (Builder $q) => $q->where('subtype', $subtype))
                ->when($key !== null, fn (Builder $q) => $q->where('key', $key))
                ->when($userId !== null, fn (Builder $q) => $q->where('user_id', $userId))
                ->whereNotNull('duration')
                ->where('created_at', '>=', $bucketStart)
                ->when($bucketEnd !== null, fn (Builder $q) => $q->where('created_at', '<', $bucketEnd))
                ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
                ->select(['created_at', 'duration'])
                // created_at, not id, as the primary sort — see cacheKeyStats()
                // for why — but unlike the count-only aggregates there, this
                // sample's actual duration *values* feed avg/p95/min/max
                // directly, so which rows land inside a tied created_at
                // second isn't safe to leave to the storage engine: without a
                // deterministic secondary sort, two runs of the same query
                // (e.g. a wire:poll refresh vs. the next page load) can each
                // pick a different subset of a busy second's rows and yield
                // different stats for what's effectively the same window —
                // the chart visibly changing shape on every refresh. `id`
                // tiebreaks deterministically without disturbing the
                // created_at-first ordering the index above is built for.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($capPerBucket);
        }

        $query = array_shift($subqueries);

        foreach ($subqueries as $subquery) {
            $query->unionAll($subquery);
        }

        return $query->get();
    }

    public function topUsers(
        string $type,
        DateTimeInterface $since,
        int $limit = 10,
        ?DateTimeInterface $until = null,
    ): Collection {
        // See cacheKeyStats() for why the GROUP BY runs over a capped
        // subquery, ordered by created_at rather than id, rather than the
        // raw filtered table directly.
        $sample = $this->query($type, $since, null, null, $until)
            ->whereNotNull('user_id')
            ->select('user_id')
            ->orderByDesc('created_at')
            ->limit($this->maxSampleRows());

        return $this->table()
            ->fromSub($sample, 't')
            ->select('user_id')
            ->selectRaw('count(*) as aggregate_count')
            ->groupBy('user_id')
            ->orderByDesc('aggregate_count')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $row->count = (int) $row->aggregate_count;
                unset($row->aggregate_count);

                return $row;
            });
    }

    public function routeStats(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): Collection {
        $rows = $this->query($type, $since, null, null, $until, $userId)
            // created_at, not id, as the primary sort — see cacheKeyStats()
            // for why — plus `id` as a deterministic tiebreaker, since
            // avg_duration/p95_duration below are computed from this
            // sample's actual values — see sampleDurationsAcrossBuckets()
            // for why a tied created_at second isn't safe to leave
            // unordered when the sample feeds a duration statistic.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            ->get(['key', 'subtype', 'duration']);

        // A single foreach pass with plain arrays, not groupBy()->map()
        // with pluck()/filter()/whereIn() chains per group: at the sample
        // cap (50k rows across thousands of routes), building and
        // re-collecting a Collection per group was costing over a second of
        // pure PHP time on top of an 85ms query — the grouping itself, not
        // the SQL, was the bottleneck here.
        $groups = [];

        foreach ($rows as $row) {
            $group = &$groups[$row->key];
            $group ??= ['count' => 0, 'success' => 0, 'client_errors' => 0, 'server_errors' => 0, 'durations' => []];

            $group['count']++;

            match ($row->subtype) {
                '1xx', '2xx', '3xx' => $group['success']++,
                '4xx' => $group['client_errors']++,
                '5xx' => $group['server_errors']++,
                default => null,
            };

            if ($row->duration !== null) {
                $group['durations'][] = (float) $row->duration;
            }
            unset($group);
        }

        $result = [];

        foreach ($groups as $key => $group) {
            $durations = $group['durations'];

            $result[] = (object) [
                'key' => $key,
                'count' => $group['count'],
                'success' => $group['success'],
                'client_errors' => $group['client_errors'],
                'server_errors' => $group['server_errors'],
                'avg_duration' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                'p95_duration' => $this->percentile($durations, 0.95),
            ];
        }

        return collect($result);
    }

    public function keyStats(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): Collection {
        $rows = $this->query($type, $since, null, null, $until, $userId)
            // created_at, not id, as the primary sort — see cacheKeyStats()
            // for why. Rows arrive newest-first, so the first row seen per
            // key is its last_seen. `id` is added as a deterministic
            // tiebreaker because — unlike cacheKeyStats' count-only
            // aggregates — avg_duration/p95_duration below are computed
            // from this sample's actual values; see
            // sampleDurationsAcrossBuckets() for why that matters.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_SAMPLE_ROWS)
            ->get(['key', 'duration', 'created_at']);

        $groups = [];

        foreach ($rows as $row) {
            $group = &$groups[$row->key];
            $group ??= ['count' => 0, 'durations' => [], 'last_seen' => $row->created_at];

            $group['count']++;

            if ($row->duration !== null) {
                $group['durations'][] = (float) $row->duration;
            }
            unset($group);
        }

        $result = [];

        foreach ($groups as $key => $group) {
            $durations = $group['durations'];

            $result[] = (object) [
                'key' => $key,
                'count' => $group['count'],
                'avg_duration' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                'p95_duration' => $this->percentile($durations, 0.95),
                'last_seen' => CarbonImmutable::parse($group['last_seen']),
            ];
        }

        return collect($result);
    }

    public function exceptionGroups(
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): Collection {
        return $this->query('exception', $since, null, null, $until, $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            ->get(['key', 'subtype', 'user_id', 'payload', 'created_at'])
            ->groupBy('key')
            ->map(function (Collection $rows, string $key) {
                // Rows arrive newest-first, so the first payload is the latest.
                $latest = json_decode($rows->first()->payload ?? '[]', true) ?: [];

                return (object) [
                    'key' => $key,
                    'class' => $latest['class'] ?? $key,
                    'message' => $latest['message'] ?? null,
                    'file' => $latest['file'] ?? null,
                    'line' => $latest['line'] ?? null,
                    'count' => $rows->count(),
                    'handled' => $rows->where('subtype', 'handled')->count(),
                    'unhandled' => $rows->where('subtype', 'unhandled')->count(),
                    'users' => $rows->pluck('user_id')->filter(fn ($id) => $id !== null)->unique()->count(),
                    'last_seen' => CarbonImmutable::parse($rows->max('created_at')),
                    'first_seen' => CarbonImmutable::parse($rows->min('created_at')),
                ];
            })
            ->values();
    }

    public function firstSeen(string $type, string $key): ?CarbonImmutable
    {
        $first = $this->table()
            ->where('type', $type)
            ->where('key', $key)
            ->min('created_at');

        return $first !== null ? CarbonImmutable::parse($first) : null;
    }

    public function syncIssues(string $type, array $lastSeenByKey): void
    {
        if ($lastSeenByKey === []) {
            return;
        }

        $existing = $this->issuesTable()
            ->where('type', $type)
            ->whereIn('key', array_keys($lastSeenByKey))
            ->get(['key', 'status', 'resolved_at'])
            ->keyBy('key');

        $now = CarbonImmutable::now();

        foreach ($lastSeenByKey as $key => $lastSeen) {
            $lastSeenValue = CarbonImmutable::instance($lastSeen);
            $row = $existing->get($key);

            if ($row === null) {
                $this->issuesTable()->insert([
                    'type' => $type,
                    'key' => $key,
                    'uuid' => Uuid::uuid7()->toString(),
                    'status' => 'open',
                    'first_seen' => $lastSeenValue,
                    'last_seen' => $lastSeenValue,
                    'resolved_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $update = ['last_seen' => $lastSeenValue, 'updated_at' => $now];

            // A resolved issue that keeps occurring reopens itself — mirrors
            // Nightwatch. An ignored one stays ignored until the user
            // manually reopens it; recurrence alone shouldn't override that.
            if ($row->status === 'resolved'
                && $row->resolved_at !== null
                && $lastSeenValue->greaterThan(CarbonImmutable::parse($row->resolved_at))) {
                $update['status'] = 'open';
                $update['resolved_at'] = null;
            }

            $this->issuesTable()->where('type', $type)->where('key', $key)->update($update);
        }
    }

    /**
     * @param  string[]  $keys
     */
    public function issueStatuses(string $type, array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        return $this->issuesTable()
            ->where('type', $type)
            ->whereIn('key', $keys)
            ->get(['id', 'uuid', 'key', 'status', 'priority', 'first_seen'])
            ->keyBy('key')
            ->map(fn ($row) => (object) [
                'id' => (int) $row->id,
                'uuid' => $row->uuid,
                'status' => $row->status,
                'priority' => $row->priority,
                'first_seen' => CarbonImmutable::parse($row->first_seen),
            ]);
    }

    public function setIssueStatus(string $type, string $key, string $status): void
    {
        if (! in_array($status, ['open', 'resolved', 'ignored'], true)) {
            return;
        }

        $now = CarbonImmutable::now();
        $exists = $this->issuesTable()->where('type', $type)->where('key', $key)->exists();

        if (! $exists) {
            // An action performed on an issue syncIssues() hasn't recorded
            // yet (edge case) — insert a fresh row rather than no-op.
            $this->issuesTable()->insert([
                'type' => $type,
                'key' => $key,
                'uuid' => Uuid::uuid7()->toString(),
                'status' => $status,
                'first_seen' => $now,
                'last_seen' => $now,
                'resolved_at' => $status === 'resolved' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->issuesTable()->where('type', $type)->where('key', $key)->update([
            'status' => $status,
            'resolved_at' => $status === 'resolved' ? $now : null,
            'updated_at' => $now,
        ]);
    }

    public function openIssueCount(): int
    {
        return $this->issuesTable()->where('status', 'open')->count();
    }

    public function setIssuePriority(string $type, string $key, string $priority): void
    {
        if (! array_key_exists($priority, Format::PRIORITIES)) {
            return;
        }

        $now = CarbonImmutable::now();
        $exists = $this->issuesTable()->where('type', $type)->where('key', $key)->exists();

        if (! $exists) {
            $this->issuesTable()->insert([
                'type' => $type,
                'key' => $key,
                'uuid' => Uuid::uuid7()->toString(),
                'status' => 'open',
                'priority' => $priority,
                'first_seen' => $now,
                'last_seen' => $now,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->issuesTable()->where('type', $type)->where('key', $key)->update([
            'priority' => $priority,
            'updated_at' => $now,
        ]);
    }

    public function findIssueByUuid(string $uuid): ?object
    {
        $row = $this->issuesTable()->where('uuid', $uuid)->first();

        if ($row === null) {
            return null;
        }

        return (object) [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'type' => $row->type,
            'key' => $row->key,
            'status' => $row->status,
            'priority' => $row->priority,
            'first_seen' => CarbonImmutable::parse($row->first_seen),
            'last_seen' => CarbonImmutable::parse($row->last_seen),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: int}
     */
    protected function bucketGrid(DateTimeInterface $since, int $buckets, ?DateTimeInterface $until = null): array
    {
        $start = CarbonImmutable::instance(
            $since instanceof CarbonImmutable ? $since : CarbonImmutable::parse($since->format('Y-m-d H:i:s'))
        );

        if ($until !== null) {
            $end = CarbonImmutable::parse($until->format('Y-m-d H:i:s'));
            $seconds = max(1, $start->diffInSeconds($end));

            return [$start, max(1, (int) round($seconds / $buckets))];
        }

        // Live window ($until === null, i.e. "up to now"): $start is
        // already pinned to a fixed grid by Card::since(), but now() itself
        // keeps advancing between polls, so the raw diff to $start grows
        // continuously for as long as that grid step stays open — rounding
        // it to the *nearest* whole second (the previous fix here) still let
        // the bucket width tip from 60 to 61 partway through every step,
        // which — multiplied out across the higher-index buckets — was
        // enough to flip which whole second their boundary landed on and
        // reshuffle the chart mid-step. Rounding the diff UP to the next
        // whole multiple of $buckets instead pins the bucket width to a
        // single value for the entire step (it only ticks over exactly when
        // $start itself jumps to the next grid point, since both are driven
        // by the same wall-clock boundary), at the cost of the window being
        // up to one bucket wider than the nominal period.
        $seconds = max(1, $start->diffInSeconds(CarbonImmutable::now()));
        $seconds = (int) (ceil($seconds / $buckets) * $buckets);

        return [$start, max(1, intdiv($seconds, $buckets))];
    }

    /**
     * strtotime(), not CarbonImmutable::parse(): this runs once per raw row
     * in durationStats()/countsPerBucket()'s raw-scan path — up to
     * MAX_SAMPLE_ROWS of them — and Carbon's object construction plus
     * format-guessing measurably added up at that volume next to
     * strtotime()'s plain C parser, for a value that's immediately reduced
     * to an int and thrown away.
     */
    protected function bucketIndex(mixed $createdAt, CarbonImmutable $start, float $bucketSize, int $buckets): int
    {
        $timestamp = is_int($createdAt) ? $createdAt : strtotime((string) $createdAt);
        $offset = $timestamp - $start->getTimestamp();

        return min($buckets - 1, max(0, (int) floor($offset / $bucketSize)));
    }

    /**
     * @param  float[]  $values
     */
    protected function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $index = (int) ceil($percentile * count($values)) - 1;

        return round((float) $values[max(0, min($index, count($values) - 1))], 2);
    }

    protected function query(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): Builder {
        return $this->table()
            ->where('type', $type)
            ->when($subtype !== null, fn (Builder $query) => $query->where('subtype', $subtype))
            ->when($key !== null, fn (Builder $query) => $query->where('key', $key))
            ->when($until !== null, fn (Builder $query) => $query->where('created_at', '<=', $until))
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            ->where('created_at', '>=', $since);
    }

    protected function table(): Builder
    {
        return $this->connection()->table($this->config['table'] ?? 'monitor_entries');
    }

    protected function aggregatesTable(): Builder
    {
        return $this->connection()->table(config('monitor.aggregates.table', 'monitor_aggregates'));
    }

    protected function issuesTable(): Builder
    {
        return $this->connection()->table(config('monitor.issues.table', 'monitor_issues'));
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->config['connection'] ?? null);
    }
}
