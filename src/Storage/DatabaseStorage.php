<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\Storage;

class DatabaseStorage implements Storage
{
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

    public function findByRequestId(string $requestId): ?object
    {
        $row = $this->table()
            ->where('type', 'request')
            ->where('request_id', $requestId)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function timelineFor(string $requestId): Collection
    {
        return $this->table()
            ->where('request_id', $requestId)
            ->where('type', '!=', 'request')
            ->orderBy('start_offset')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->hydrate($row));
    }

    public function cacheKeyStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection
    {
        return $this->table()
            ->where('type', 'cache')
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
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
        return $this->table()
            ->where('type', 'slow_query')
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->get(['key', 'duration', 'payload'])
            ->map(function ($row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];

                return (object) [
                    'key' => (string) $row->key,
                    'connection' => $payload['connection'] ?? 'default',
                    'duration' => $row->duration !== null ? (float) $row->duration : null,
                ];
            })
            ->groupBy(fn ($row) => $row->key.'@@'.$row->connection)
            ->map(function (Collection $rows) {
                $durations = $rows->pluck('duration')->filter(fn ($d) => $d !== null)->values()->all();
                $first = $rows->first();

                return (object) [
                    'key' => $first->key,
                    'connection' => $first->connection,
                    'calls' => $rows->count(),
                    'total' => round(array_sum($durations), 2),
                    'avg' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                    'p95' => $this->percentile($durations, 0.95),
                ];
            })
            ->values();
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

        return $this->query($type, $since, $subtype, null, $until)
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
        $row = $this->query($type, $since, $subtype, $key, $until, $userId)
            ->selectRaw('count(*) as aggregate_count, avg(duration) as avg_duration, max(duration) as max_duration, min(duration) as min_duration')
            ->first();

        return (object) [
            'count' => (int) ($row->aggregate_count ?? 0),
            'avg_duration' => isset($row->avg_duration) ? round((float) $row->avg_duration, 2) : null,
            'max_duration' => isset($row->max_duration) ? (float) $row->max_duration : null,
            'min_duration' => isset($row->min_duration) ? (float) $row->min_duration : null,
        ];
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

        $counts = array_fill(0, $buckets, 0);

        $this->query($type, $since, $subtype, $key, $until, $userId)
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
        ?int $userId = null,
    ): object {
        [$start, $bucketSize] = $this->bucketGrid($since, $buckets, $until);

        $perBucket = array_fill(0, $buckets, []);
        $all = [];

        $this->query($type, $since, $subtype, $key, $until, $userId)
            ->whereNotNull('duration')
            ->get(['created_at', 'duration'])
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

    public function topUsers(
        string $type,
        DateTimeInterface $since,
        int $limit = 10,
        ?DateTimeInterface $until = null,
    ): Collection {
        return $this->query($type, $since, null, null, $until)
            ->whereNotNull('user_id')
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
        return $this->query($type, $since, null, null, $until, $userId)
            ->get(['key', 'subtype', 'duration'])
            ->groupBy('key')
            ->map(function (Collection $rows, string $key) {
                $durations = $rows->pluck('duration')->filter(fn ($duration) => $duration !== null)->map(fn ($duration) => (float) $duration)->values()->all();

                return (object) [
                    'key' => $key,
                    'count' => $rows->count(),
                    'success' => $rows->whereIn('subtype', ['1xx', '2xx', '3xx'])->count(),
                    'client_errors' => $rows->where('subtype', '4xx')->count(),
                    'server_errors' => $rows->where('subtype', '5xx')->count(),
                    'avg_duration' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                    'p95_duration' => $this->percentile($durations, 0.95),
                ];
            })
            ->values();
    }

    public function exceptionGroups(
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): Collection {
        return $this->query('exception', $since, null, null, $until, $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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

    /**
     * @return array{0: CarbonImmutable, 1: float}
     */
    protected function bucketGrid(DateTimeInterface $since, int $buckets, ?DateTimeInterface $until = null): array
    {
        $start = CarbonImmutable::instance(
            $since instanceof CarbonImmutable ? $since : CarbonImmutable::parse($since->format('Y-m-d H:i:s'))
        );

        $end = $until !== null
            ? CarbonImmutable::parse($until->format('Y-m-d H:i:s'))
            : CarbonImmutable::now();

        $seconds = max(1, $start->diffInSeconds($end));

        return [$start, $seconds / $buckets];
    }

    protected function bucketIndex(mixed $createdAt, CarbonImmutable $start, float $bucketSize, int $buckets): int
    {
        $offset = CarbonImmutable::parse($createdAt)->getTimestamp() - $start->getTimestamp();

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

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->config['connection'] ?? null);
    }
}
