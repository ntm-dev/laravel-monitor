<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\AggregateStorage;
use LaravelMonitor\Recorders\Requests;
use LaravelMonitor\Storage\Concerns\BuildsQueries;
use LaravelMonitor\Storage\Concerns\UsesAggregatesTable;
use LaravelMonitor\Support\HttpStatusGroup;
use LaravelMonitor\Support\RecordType;

use function is_array;

class DatabaseAggregateStorage implements AggregateStorage
{
    use BuildsQueries;
    use UsesAggregatesTable;

    public function aggregateByKey(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        int $limit = 10,
        string $orderBy = 'count',
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
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
        $sample = $this->query($type, $since, $subtype, null, $until, $userId)
            ->select(['key', 'duration', 'created_at', 'user_id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows());

        return $this->table()
            ->fromSub($sample, 't')
            ->select('key')
            ->selectRaw('count(*) as aggregate_count, avg(duration) as avg_duration, max(duration) as max_duration, max(created_at) as last_seen, count(distinct user_id) as users')
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
                $row->users = (int) $row->users;

                return $row;
            });
    }

    public function stats(
        string $type,
        DateTimeInterface $since,
        string|array|null $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
        ?float $minDuration = null,
    ): object {
        // The aggregates table only carries type+subtype totals (see
        // countsPerBucketFromAggregates()'s own docs) — no per-row duration
        // to compare against a bar, and no array-of-subtypes breakdown — so
        // either one always falls back to the raw scan below.
        if ($minDuration === null && ! is_array($subtype) && $key === null && $userId === null && $this->aggregatesCover($type, $since, $until)) {
            return $this->statsFromAggregates($type, $subtype, $since, $until);
        }

        $row = $this->query($type, $since, $subtype, $key, $until, $userId, $minDuration)
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
        int|string|null $userId = null,
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

    public function countsPerBucket(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
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

    public function durationStats(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $key = null,
        ?string $subtype = null,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
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
     * Samples up to maxSampleRows() / $buckets rows per bucket instead of the
     * most recent maxSampleRows() overall. A flat "most recent N" cap starves
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
        int|string|null $userId,
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

    public function routeStats(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
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
            // Every method that hit no Laravel route (404s, arbitrary probed
            // paths) is still stored under its own "{METHOD} Unmatched
            // Route" key (see Requests::record()) — collapse them into one
            // group here, or they'd fragment the route list into one row
            // per method instead of the single record the list should show.
            $unmatched = $type === RecordType::Request->value
                && $row->key !== null
                && Str::endsWith($row->key, ' '.Requests::UNMATCHED_ROUTE);
            $groupKey = $unmatched ? Requests::UNMATCHED_ROUTE : $row->key;

            $group = &$groups[$groupKey];
            $group ??= ['count' => 0, 'success' => 0, 'client_errors' => 0, 'server_errors' => 0, 'network_errors' => 0, 'durations' => [], 'methods' => []];

            if ($unmatched) {
                $group['methods'][Str::before($row->key, ' ')] = true;
            }

            $group['count']++;

            match ($row->subtype) {
                HttpStatusGroup::Informational->value, HttpStatusGroup::Successful->value, HttpStatusGroup::Redirection->value => $group['success']++,
                HttpStatusGroup::ClientError->value => $group['client_errors']++,
                HttpStatusGroup::ServerError->value => $group['server_errors']++,
                HttpStatusGroup::NetworkError->value => $group['network_errors']++,
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
            $methods = array_keys($group['methods']);
            sort($methods);

            $result[] = (object) [
                'key' => $key,
                // Distinct methods behind the merged Unmatched Route row
                // (capped by the sample above), null for every ordinary
                // route — see Livewire\Requests::presentRoute().
                'methods' => $key === Requests::UNMATCHED_ROUTE ? $methods : null,
                'count' => $group['count'],
                'success' => $group['success'],
                'client_errors' => $group['client_errors'],
                'server_errors' => $group['server_errors'],
                'network_errors' => $group['network_errors'],
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
        int|string|null $userId = null,
        ?string $subtype = null,
    ): Collection {
        $rows = $this->query($type, $since, $subtype, null, $until, $userId)
            // created_at, not id, as the primary sort — see cacheKeyStats()
            // for why. Rows arrive newest-first, so the first row seen per
            // key is its last_seen. `id` is added as a deterministic
            // tiebreaker because — unlike cacheKeyStats' count-only
            // aggregates — avg_duration/p95_duration below are computed
            // from this sample's actual values; see
            // sampleDurationsAcrossBuckets() for why that matters.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
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

    public function latestPayloadByKey(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int $limit = 1000,
    ): Collection {
        // Two narrow queries rather than one sampled scan: the GROUP BY reads
        // only ids, so the payload column — by far the widest in the table —
        // is fetched for one row per key instead of for the whole sample.
        // `max(id)`, not max(created_at), so a tie within the same second
        // still resolves to a single, actually-latest row.
        $ids = $this->query($type, $since, null, null, $until)
            ->selectRaw('max(id) as latest_id')
            ->groupBy('key')
            ->limit($limit)
            ->pluck('latest_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->table()
            ->whereIn('id', $ids->all())
            ->get(['key', 'payload'])
            ->mapWithKeys(static fn ($row) => [$row->key => json_decode($row->payload ?? '[]', true) ?: []]);
    }
}
