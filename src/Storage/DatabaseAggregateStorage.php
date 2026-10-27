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
use LaravelMonitor\Support\StorageTime;

use function in_array;
use function is_array;
use function func_get_args;
use function max;

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
        return $this->cacheRemember(
            __FUNCTION__,
            func_get_args(),
            $until,
            fn () => $this->aggregateByKeyUncached($type, $since, $subtype, $limit, $orderBy, $until, $userId),
        );
    }

    protected function aggregateByKeyUncached(
        string $type,
        DateTimeInterface $since,
        ?string $subtype,
        int $limit,
        string $orderBy,
        ?DateTimeInterface $until,
        int|string|null $userId,
    ): Collection {
        if (! in_array($orderBy, ['count', 'avg_duration', 'max_duration', 'last_seen'], true)) {
            $orderBy = 'count';
        }

        $orderColumn = $orderBy === 'count' ? 'aggregate_count' : $orderBy;

        // Capped subquery: see DatabaseCacheAndQueryStorage::cacheKeyStatsUncached().
        // `id` tiebreak: see sampleDurationsAcrossBuckets().
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
        // monitor_aggregates holds single-subtype totals only: no per-row
        // duration filter, subtype list, key or user.
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

        // Capped like every raw-scan fallback; an unbounded pluck() can exhaust memory on wide ranges.
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
     * Up to maxSampleRows() / $buckets rows per bucket (one UNION ALL query), so a global cap
     * can't starve the earlier buckets. The last bucket is bounded only by $until, since its
     * rounded end could fall just short of the range.
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
            $bucketStart = StorageTime::fromTimestamp($startTimestamp + (int) round($i * $bucketSize));
            $bucketEnd = $isLastBucket
                ? null
                : StorageTime::fromTimestamp($startTimestamp + (int) round(($i + 1) * $bucketSize));

            $subqueries[] = $this->table()
                ->where('type', $type)
                ->when($subtype !== null, fn (Builder $q) => $q->where('subtype', $subtype))
                ->when($key !== null, fn (Builder $q) => $q->where('key', $key))
                ->tap(fn (Builder $q) => $this->whereUser($q, $userId))
                ->whereNotNull('duration')
                ->where('created_at', '>=', $bucketStart)
                ->when($bucketEnd !== null, fn (Builder $q) => $q->where('created_at', '<', $bucketEnd))
                ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
                ->select(['created_at', 'duration'])
                // Sampled durations feed the stats, so `id` breaks created_at ties
                // deterministically; otherwise each poll could pick different rows.
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
        return $this->cacheRemember(
            __FUNCTION__,
            func_get_args(),
            $until,
            fn () => $this->routeStatsUncached($type, $since, $until, $userId),
        );
    }

    protected function routeStatsUncached(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until,
        int|string|null $userId,
    ): Collection {
        $rows = $this->query($type, $since, null, null, $until, $userId)
            // `id` tiebreak: see sampleDurationsAcrossBuckets().
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            ->get(['key', 'subtype', 'duration', 'created_at']);

        // Plain arrays, not a Collection per group: too slow at the sample cap.
        $groups = [];

        foreach ($rows as $row) {
            // Merge every "{METHOD} Unmatched Route" key into one row.
            $unmatched = $type === RecordType::Request->value
                && $row->key !== null
                && Str::endsWith($row->key, ' '.Requests::UNMATCHED_ROUTE);
            $groupKey = $unmatched ? Requests::UNMATCHED_ROUTE : $row->key;

            $group = &$groups[$groupKey];
            // Rows arrive newest-first, so the first row seen is last_seen.
            $group ??= ['count' => 0, 'success' => 0, 'client_errors' => 0, 'server_errors' => 0, 'network_errors' => 0, 'durations' => [], 'methods' => [], 'last_seen' => $row->created_at];

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
                // Only the merged Unmatched Route row lists its methods.
                'methods' => $key === Requests::UNMATCHED_ROUTE ? $methods : null,
                'count' => $group['count'],
                'success' => $group['success'],
                'client_errors' => $group['client_errors'],
                'server_errors' => $group['server_errors'],
                'network_errors' => $group['network_errors'],
                'avg_duration' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                'p95_duration' => $this->percentile($durations, 0.95),
                'max_duration' => $durations === [] ? null : max($durations),
                'last_seen' => CarbonImmutable::parse($group['last_seen']),
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
            // Newest-first, so the first row per key is last_seen.
            // `id` tiebreak: see sampleDurationsAcrossBuckets().
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
        // Group on ids only, then fetch the wide payload for one row per key.
        // max(id) resolves same-second ties to a single row.
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
