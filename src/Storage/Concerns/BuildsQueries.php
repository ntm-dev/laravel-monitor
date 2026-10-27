<?php

namespace LaravelMonitor\Storage\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LaravelMonitor\Recorders\Requests;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Settings;
use LaravelMonitor\Support\UserFilter;
use stdClass;

use function is_array;
use function is_string;
use function serialize;
use function unserialize;

/**
 * Shared plumbing for the Database*Storage classes: construction, the base filtered
 * query(), row hydration, table accessors, the sampling cap, bucket math and result caching.
 */
trait BuildsQueries
{
    /**
     * Row cap for raw scans: bounds PHP memory where rows are grouped/percentiled in PHP,
     * and bounds GROUP BY cost via a LIMITed subquery. Capped queries take the most recent
     * rows (created_at DESC). A method, not a constant, so tests can shrink it.
     */
    protected function maxSampleRows(): int
    {
        return 50000;
    }

    public function __construct(
        protected DatabaseManager $db,
        protected array $config = [],
    ) {
    }

    /** Decode the JSON payload and parse timestamps on a raw row. */
    protected function hydrate(object $row): object
    {
        $row->payload = json_decode($row->payload ?? '[]', true) ?: [];
        $row->created_at = CarbonImmutable::parse($row->created_at);

        return $row;
    }

    /** Unix timestamp for a DateTimeInterface, matching the bucket column's storage unit. */
    protected function toTimestamp(DateTimeInterface $date): int
    {
        return ($date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date->format('Y-m-d H:i:s')))->getTimestamp();
    }

    /**
     * Keeps microseconds (toDateTimeString() drops them) for the timestamp(6) issue columns,
     * so syncIssues()' last_seen vs resolved_at check never misfires within one second.
     */
    protected function preciseTimestamp(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
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

        // Live window: round the span up to a multiple of $buckets so the bucket
        // width stays fixed between polls while $start (grid-aligned by
        // Card::since()) is unchanged. The window may be up to one bucket wider.
        $seconds = max(1, $start->diffInSeconds(CarbonImmutable::now()));
        $seconds = (int) (ceil($seconds / $buckets) * $buckets);

        return [$start, max(1, intdiv($seconds, $buckets))];
    }

    /**
     * Runs once per sampled row, so it parses with strtotime() rather than Carbon.
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
        return \LaravelMonitor\Support\Percentile::of($values, $percentile);
    }

    protected function query(
        string $type,
        DateTimeInterface $since,
        string|array|null $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
        ?float $minDuration = null,
    ): Builder {
        return $this->table()
            ->where('type', $type)
            ->when($subtype !== null, fn (Builder $query) => is_array($subtype)
                ? $query->whereIn('subtype', $subtype)
                : $query->where('subtype', $subtype))
            ->when($key !== null, fn (Builder $query) => $this->whereKey($query, $type, $key))
            ->when($until !== null, fn (Builder $query) => $query->where('created_at', '<=', $until))
            ->tap(fn (Builder $query) => $this->whereUser($query, $userId))
            ->when($minDuration !== null, fn (Builder $query) => $query->where('duration', '>=', $minDuration))
            ->where('created_at', '>=', $since);
    }

    /**
     * Apply the dashboard's user scope: the AUTHENTICATED sentinel drops
     * guests, GUEST keeps only them, any other non-null value matches that one
     * user, null is a no-op.
     */
    protected function whereUser(Builder $query, int|string|null $userId): Builder
    {
        return match ($userId) {
            null => $query,
            UserFilter::AUTHENTICATED => $query->whereNotNull('user_id'),
            UserFilter::GUEST => $query->whereNull('user_id'),
            default => $query->where('user_id', $userId),
        };
    }

    /**
     * The bare Requests::UNMATCHED_ROUTE key (routeStats()' merged row) matches every
     * stored "{METHOD} Unmatched Route" key.
     */
    protected function whereKey(Builder $query, string $type, string $key): Builder
    {
        if ($type === RecordType::Request->value && $key === Requests::UNMATCHED_ROUTE) {
            return $query->where('key', 'like', '% '.Requests::UNMATCHED_ROUTE);
        }

        return $query->where('key', $key);
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

    /**
     * Caches a raw-scan result keyed by method + args. Uses the Store directly so no cache
     * events reach Recorders\CacheInteractions; values are pre-serialized so a restrictive
     * `cache.serializable_classes` can't return __PHP_Incomplete_Class.
     */
    protected function cacheRemember(string $method, array $args, ?DateTimeInterface $until, Closure $callback): mixed
    {
        if (! config('monitor.aggregate_cache.enabled', true)) {
            return $callback();
        }

        $store = $this->aggregateCacheStore();
        $key = 'monitor:agg:'.md5(static::class.'::'.$method.':'.serialize($args));

        $cached = $store->get($key);

        if (is_string($cached)) {
            $value = unserialize($cached, ['allowed_classes' => [Collection::class, stdClass::class, CarbonImmutable::class]]);

            if ($value !== false) {
                return $value;
            }
        }

        $value = $callback();

        // A closed range never changes; a live window is valid only until the next poll.
        $ttl = $until !== null
            ? (int) config('monitor.aggregate_cache.closed_ttl', 3600)
            : (int) (config('monitor.aggregate_cache.live_ttl') ?? config('monitor.refresh', 10));

        $store->put($key, serialize($value), $ttl);

        return $value;
    }

    /**
     * An empty store setting means the app's default store; any other name is registered
     * as its own 'monitor_aggregate_<name>' store (see aggregateCacheDriverConfig()).
     */
    protected function aggregateCacheStore(): Store
    {
        $name = config('monitor.aggregate_cache.store') ?: null;

        if ($name === null) {
            return app('cache')->store(null)->getStore();
        }

        $registeredAs = "monitor_aggregate_{$name}";

        if (config("cache.stores.{$registeredAs}") === null) {
            config(["cache.stores.{$registeredAs}" => $this->aggregateCacheDriverConfig($name)]);
        }

        return app('cache')->store($registeredAs)->getStore();
    }

    /**
     * 'file'/'database' build an isolated config from package defaults (plus saved options
     * in "custom" mode); other drivers copy `cache.stores.<name>`.
     */
    protected function aggregateCacheDriverConfig(string $name): array
    {
        $driver = Settings::aggregateCacheDriver($name);

        $mode = config('monitor.aggregate_cache.mode');

        // "app" mode reuses the host app's own config for this driver.
        if ($mode === 'app' && Settings::aggregateCacheHasAppConfig($driver)) {
            return [...config("cache.stores.{$driver}", []), 'driver' => $driver];
        }

        $options = $mode === 'custom' ? (array) config('monitor.aggregate_cache.options', []) : [];

        return match ($driver) {
            'file' => [
                'driver' => 'file',
                ...Settings::aggregateCacheDefaults('file'),
                ...$options,
            ],
            'database' => [
                'driver' => 'database',
                'table' => config('monitor.aggregate_cache.table', 'monitor_cache'),
                ...Settings::aggregateCacheDefaults('database'),
                'lock_connection' => null,
                ...$options,
            ],
            default => [...config("cache.stores.{$name}", []), 'driver' => $driver],
        };
    }
}
