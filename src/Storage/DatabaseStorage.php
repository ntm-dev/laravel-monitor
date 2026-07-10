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
    ): Collection {
        return $this->query($type, $since, $subtype)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $row->payload = json_decode($row->payload ?? '[]', true) ?: [];
                $row->created_at = CarbonImmutable::parse($row->created_at);

                return $row;
            });
    }

    public function aggregateByKey(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        int $limit = 10,
        string $orderBy = 'count',
    ): Collection {
        if (! in_array($orderBy, ['count', 'avg_duration', 'max_duration', 'last_seen'], true)) {
            $orderBy = 'count';
        }

        $orderColumn = $orderBy === 'count' ? 'aggregate_count' : $orderBy;

        return $this->query($type, $since, $subtype)
            ->select('key')
            ->selectRaw('count(*) as aggregate_count, avg(duration) as avg_duration, max(duration) as max_duration, max(created_at) as last_seen')
            ->groupBy('key')
            ->orderByDesc($orderColumn)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $row->count = (int) $row->aggregate_count;
                unset($row->aggregate_count);
                $row->avg_duration = $row->avg_duration !== null ? (float) $row->avg_duration : null;
                $row->max_duration = $row->max_duration !== null ? (int) $row->max_duration : null;
                $row->last_seen = $row->last_seen !== null ? CarbonImmutable::parse($row->last_seen) : null;

                return $row;
            });
    }

    public function stats(string $type, DateTimeInterface $since, ?string $subtype = null): object
    {
        $row = $this->query($type, $since, $subtype)
            ->selectRaw('count(*) as aggregate_count, avg(duration) as avg_duration, max(duration) as max_duration')
            ->first();

        return (object) [
            'count' => (int) ($row->aggregate_count ?? 0),
            'avg_duration' => isset($row->avg_duration) ? (float) $row->avg_duration : null,
            'max_duration' => isset($row->max_duration) ? (int) $row->max_duration : null,
        ];
    }

    public function countsPerBucket(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $subtype = null,
    ): array {
        $start = CarbonImmutable::instance(
            $since instanceof CarbonImmutable ? $since : CarbonImmutable::parse($since->format('Y-m-d H:i:s'))
        );
        $seconds = max(1, $start->diffInSeconds(CarbonImmutable::now()));
        $bucketSize = $seconds / $buckets;

        $counts = array_fill(0, $buckets, 0);

        $this->query($type, $since, $subtype)
            ->pluck('created_at')
            ->each(function ($createdAt) use (&$counts, $start, $bucketSize, $buckets) {
                $offset = CarbonImmutable::parse($createdAt)->getTimestamp() - $start->getTimestamp();
                $index = min($buckets - 1, max(0, (int) floor($offset / $bucketSize)));
                $counts[$index]++;
            });

        return $counts;
    }

    public function topUsers(string $type, DateTimeInterface $since, int $limit = 10): Collection
    {
        return $this->query($type, $since)
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

    protected function query(string $type, DateTimeInterface $since, ?string $subtype = null): Builder
    {
        return $this->table()
            ->where('type', $type)
            ->when($subtype !== null, fn (Builder $query) => $query->where('subtype', $subtype))
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
