<?php

namespace LaravelMonitor\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use LaravelMonitor\Entry;

interface Storage
{
    /**
     * Persist a batch of entries.
     *
     * @param  Entry[]  $entries
     */
    public function store(array $entries): void;

    /**
     * Delete entries created before the given time, or everything when null.
     * Returns the number of deleted entries when known, -1 otherwise.
     */
    public function purge(?DateTimeInterface $before = null): int;

    /**
     * Latest entries of a type, newest first. Each item exposes:
     * key, subtype, payload (array), duration, user_id, created_at (Carbon).
     */
    public function recent(
        string $type,
        DateTimeInterface $since,
        int $limit = 50,
        ?string $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
    ): Collection;

    /**
     * Group entries by key. Each item exposes:
     * key, count, avg_duration, max_duration, last_seen.
     *
     * $orderBy is one of: count, avg_duration, max_duration, last_seen.
     * Sampled at high volume — see durationStats() — `count` is exact only
     * up to DatabaseStorage::MAX_SAMPLE_ROWS matching rows.
     */
    public function aggregateByKey(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        int $limit = 10,
        string $orderBy = 'count',
        ?DateTimeInterface $until = null,
    ): Collection;

    /**
     * Totals for a type: object with count, avg_duration, max_duration,
     * min_duration, total_duration.
     */
    public function stats(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): object;

    /**
     * Same shape as stats(), but broken down by subtype in a single query —
     * for a dashboard card that needs totals for several subtypes at once
     * (e.g. 2xx/3xx/4xx/5xx) instead of calling stats() once per subtype.
     * Keyed by subtype; a subtype with no matching entries is simply absent
     * from the collection rather than present with zeroes.
     *
     * @return Collection<string, object{count: int, avg_duration: ?float, max_duration: ?float, min_duration: ?float, total_duration: ?float}>
     */
    public function statsBySubtype(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
        ?string $key = null,
    ): Collection;

    /**
     * Entry counts split into $buckets equal time slices between $since and
     * $until (defaults to now) — used to draw activity charts. Reads
     * monitor_aggregates when it covers the range and no key/user filter is
     * given; otherwise scans raw entries, sampled at high volume — see
     * durationStats() — so counts are exact only up to
     * DatabaseStorage::MAX_SAMPLE_ROWS matching rows.
     *
     * @return int[]
     */
    public function countsPerBucket(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): array;

    /**
     * Duration distribution for a type: object with min, max, avg, p95 plus
     * avg_per_bucket / p95_per_bucket arrays (float|null per time slice).
     * Percentiles are computed in PHP (no portable cross-driver SQL
     * percentile), so the underlying row fetch is capped at the most recent
     * DatabaseStorage::MAX_SAMPLE_ROWS matches — an approximation past that
     * volume, not an exact percentile.
     */
    public function durationStats(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $key = null,
        ?string $subtype = null,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): object;

    /**
     * Users generating the most entries of a type. Each item exposes:
     * user_id, count. Sampled at high volume — see durationStats() — `count`
     * is exact only up to DatabaseStorage::MAX_SAMPLE_ROWS matching rows.
     */
    public function topUsers(
        string $type,
        DateTimeInterface $since,
        int $limit = 10,
        ?DateTimeInterface $until = null,
    ): Collection;

    /**
     * Per-route breakdown for a type: one item per key exposing
     * key, count, success, client_errors, server_errors, avg_duration, p95_duration.
     * Sampled at high volume — see durationStats() — so `count` and the
     * error breakdowns are exact only up to DatabaseStorage::MAX_SAMPLE_ROWS
     * matching rows; use stats()/aggregateByKey() for exact totals.
     */
    public function routeStats(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): Collection;

    /**
     * Grouped exceptions: one item per fingerprint key exposing
     * key, class, message, file, line, count, handled, unhandled, users
     * (distinct impacted users), first_seen and last_seen. Sampled at high
     * volume — see durationStats() — `count` and the handled/unhandled/users
     * tallies are exact only up to DatabaseStorage::MAX_SAMPLE_ROWS matching
     * rows.
     */
    public function exceptionGroups(
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        ?int $userId = null,
    ): Collection;

    /**
     * Earliest occurrence (across all retained data, ignoring the range) of a
     * given key, or null when it has never been seen.
     */
    public function firstSeen(string $type, string $key): ?CarbonImmutable;

    /**
     * The root `request` entry recorded with the given correlation id, or
     * null when unknown. Exposes the same fields as recent() rows plus
     * request_id and start_offset.
     */
    public function findByRequestId(string $requestId): ?object;

    /**
     * Every non-request entry correlated to the given request, ordered by
     * where it started on the timeline. Same row shape as findByRequestId().
     */
    public function timelineFor(string $requestId): Collection;

    /**
     * Per-key cache breakdown, unsorted: one row per key exposing key,
     * hit_ratio, hits, misses, writes, deletes, failures, total. Callers
     * sort/paginate themselves, same convention as routeStats(). Sampled at
     * high volume — see durationStats() — `total` and the hit/miss/write/
     * delete/failure tallies are exact only up to
     * DatabaseStorage::MAX_SAMPLE_ROWS matching rows.
     */
    public function cacheKeyStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

    /**
     * Per (query, connection) breakdown, unsorted: one row per pair exposing
     * key (the SQL), connection, calls, total, avg, p95. Callers sort/
     * paginate themselves, same convention as routeStats(). Sampled at high
     * volume — see durationStats() — `calls`/`total` are exact only up to
     * DatabaseStorage::MAX_SAMPLE_ROWS matching rows.
     */
    public function queryStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

    /**
     * "METHOD /path" label for each of the given request ids, keyed by
     * request_id, in a single query — batches what would otherwise be one
     * findByRequestId() call per row (e.g. a Query Detail page's calls table).
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function requestLabels(array $requestIds): Collection;
}
