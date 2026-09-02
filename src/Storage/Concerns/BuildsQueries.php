<?php

namespace LaravelMonitor\Storage\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LaravelMonitor\Recorders\Requests;
use LaravelMonitor\Support\RecordType;

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
     */
    protected const MAX_SAMPLE_ROWS = 50000;

    /**
     * A method, not a bare reference to the constant, so tests can subclass
     * a Database*Storage class and shrink this to reproduce cap-related
     * sampling behavior (e.g. an early bucket losing all representation once
     * total volume exceeds the cap) without needing to actually insert tens
     * of thousands of rows.
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
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            ->when($minDuration !== null, fn (Builder $query) => $query->where('duration', '>=', $minDuration))
            ->where('created_at', '>=', $since);
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
}
