<?php

namespace LaravelMonitor\Storage\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LaravelMonitor\Recorders\Requests;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Settings;
use LaravelMonitor\Support\UserFilter;

use function is_array;

/**
 * Shared low-level query/plumbing helpers every Database*Storage class builds
 * on: construction, the base filtered query(), raw-row hydration, the table
 * accessors, the raw-scan sampling cap, and the bucket-math helpers
 * countsPerBucket()/durationStats() and friends share. Composed into every
 * one of the narrow Storage classes (Contracts\AggregateStorage,
 * Contracts\UserStorage, ...) instead of duplicated across them, without
 * pulling them all into one bound service the way a shared base class would.
 */
trait BuildsQueries
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
     *
     * A method, not a class constant, so tests can subclass a Database*Storage
     * class and shrink this to reproduce cap-related sampling behavior without
     * needing to actually insert tens of thousands of rows. (Traits can't
     * declare constants until PHP 8.2, and this package supports 8.1, so the
     * cap lives here rather than as a MAX_SAMPLE_ROWS const.)
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
     * format('Y-m-d H:i:s.u'), not toDateTimeString(): the latter always
     * drops the fractional seconds — see store()'s own version of this
     * comment. monitor_issues.first_seen/last_seen/resolved_at are
     * timestamp(6) specifically so syncIssues()'s recurrence check (last_seen
     * vs. resolved_at) compares two values at the same precision as the
     * microsecond-precision monitor_entries.created_at last_seen is read
     * from — otherwise a resolve landing in the same wall-clock second as
     * the last occurrence truncates resolved_at below last_seen and the
     * issue looks like it already recurred.
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
     * maxSampleRows() of them — and Carbon's object construction plus
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
     * Every request that matched no Laravel route is still stored under its
     * own "{METHOD} Unmatched Route" key (see Requests::record()), but
     * routeStats() collapses all of them into one Requests::UNMATCHED_ROUTE
     * row on the route list — so a lookup for that bare sentinel has to
     * expand back into every method variant instead of an exact match.
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
     * Wraps a raw-scan list query (queryStats(), routeStats(), ...) in a
     * short cache entry keyed by every argument the caller passed. Reads/
     * writes the cache Store directly, not Cache::remember(), so it never
     * fires the events Recorders\CacheInteractions would record as app
     * cache activity.
     */
    protected function cacheRemember(string $method, array $args, ?DateTimeInterface $until, Closure $callback): mixed
    {
        if (! config('monitor.aggregate_cache.enabled', true)) {
            return $callback();
        }

        $store = $this->aggregateCacheStore();
        $key = 'monitor:agg:'.md5(static::class.'::'.$method.':'.serialize($args));

        $cached = $store->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        // A closed range's result never changes once past; a live "up to
        // now" window is only valid until the next poll picks up new rows.
        $ttl = $until !== null
            ? (int) config('monitor.aggregate_cache.closed_ttl', 3600)
            : (int) (config('monitor.aggregate_cache.live_ttl') ?? config('monitor.refresh', 10));

        $store->put($key, $value, $ttl);

        return $value;
    }

    /**
     * '' (not null) means "the host app's own cache.default" — the only
     * choice allowed to share the app's own store. Every other selection
     * gets a dedicated 'monitor_aggregate_<name>' store, never `cache.
     * stores.<name>` verbatim — see aggregateCacheDriverConfig().
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
     * 'file'/'database' (the drivers Settings exposes options for) build
     * their own config from scratch — never `cache.stores.$name` — so they
     * stay isolated even with no options set. Every other driver has none
     * to expose (Settings::AGGREGATE_CACHE_SENSITIVE_DRIVERS), so it falls
     * back to `cache.stores.$name` as-is — the dedicated store the admin
     * was told to define, per the Settings page's own warning for these.
     */
    protected function aggregateCacheDriverConfig(string $name): array
    {
        $driver = Settings::aggregateCacheDriver($name);

        // Opt-in escape hatch: the admin explicitly asked to reuse the host
        // app's own store for this driver instead of an isolated default.
        if (config('monitor.aggregate_cache.use_app_config') && Settings::aggregateCacheHasAppConfig($driver)) {
            return [...config("cache.stores.{$driver}", []), 'driver' => $driver];
        }

        $options = (array) config('monitor.aggregate_cache.options', []);

        return match ($driver) {
            'file' => [
                'driver' => 'file',
                'path' => storage_path('framework/cache/monitor-aggregate'),
                'lock_path' => storage_path('framework/cache/monitor-aggregate'),
                ...$options,
            ],
            'database' => [
                'driver' => 'database',
                'table' => config('monitor.aggregate_cache.table', 'monitor_cache'),
                'connection' => null,
                'lock_connection' => null,
                ...$options,
            ],
            default => [...config("cache.stores.{$name}", []), 'driver' => $driver],
        };
    }
}
