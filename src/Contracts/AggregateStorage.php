<?php

namespace LaravelMonitor\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Generic per-key/per-bucket/per-subtype aggregate queries — the workhorse
 * stats behind every list/chart card (Requests, Cache, Jobs, ...) that isn't
 * specific to users, exceptions, or issues.
 */
interface AggregateStorage
{
    /**
     * Group entries by key. Each item exposes:
     * key, count, avg_duration, max_duration, last_seen, users (distinct
     * user_id count).
     *
     * $orderBy is one of: count, avg_duration, max_duration, last_seen.
     * Sampled at high volume — see durationStats() — `count`/`users` are
     * exact only up to DatabaseAggregateStorage::MAX_SAMPLE_ROWS matching rows.
     */
    public function aggregateByKey(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        int $limit = 10,
        string $orderBy = 'count',
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
    ): Collection;

    /**
     * Totals for a type: object with count, avg_duration, max_duration,
     * min_duration, total_duration. $subtype accepts an array the same way
     * TimelineStorage::recent() does. $minDuration restricts to entries at or
     * above it (a duration filter tab's own badge count).
     */
    public function stats(
        string $type,
        DateTimeInterface $since,
        string|array|null $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
        ?float $minDuration = null,
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
        int|string|null $userId = null,
        ?string $key = null,
    ): Collection;

    /**
     * Entry counts split into $buckets equal time slices between $since and
     * $until (defaults to now) — used to draw activity charts. Reads
     * monitor_aggregates when it covers the range and no key/user filter is
     * given; otherwise scans raw entries, sampled at high volume — see
     * durationStats() — so counts are exact only up to
     * DatabaseAggregateStorage::MAX_SAMPLE_ROWS matching rows.
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
        int|string|null $userId = null,
    ): array;

    /**
     * Duration distribution for a type: object with min, max, avg, p95 plus
     * avg_per_bucket / p95_per_bucket arrays (float|null per time slice).
     * Percentiles are computed in PHP (no portable cross-driver SQL
     * percentile), so the underlying row fetch is capped at the most recent
     * DatabaseAggregateStorage::MAX_SAMPLE_ROWS matches — an approximation past
     * that volume, not an exact percentile.
     */
    public function durationStats(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $key = null,
        ?string $subtype = null,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
    ): object;

    /**
     * Per-route breakdown for a type: one item per key exposing
     * key, methods, count, success, client_errors, server_errors, avg_duration, p95_duration.
     * For type 'request', every entry whose key ends in " Recorders\Requests::UNMATCHED_ROUTE"
     * (no matched Laravel route) is merged into a single row keyed by the bare
     * Requests::UNMATCHED_ROUTE sentinel, with `methods` listing the distinct
     * HTTP methods behind it (null for every ordinary route).
     * Sampled at high volume — see durationStats() — so `count` and the
     * error breakdowns are exact only up to DatabaseAggregateStorage::MAX_SAMPLE_ROWS
     * matching rows; use stats()/aggregateByKey() for exact totals.
     */
    public function routeStats(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
    ): Collection;

    /**
     * Generic per-key breakdown for a type with no status/subtype dimension
     * to split by (unlike routeStats()) — one item per key exposing key,
     * count, avg_duration, p95_duration, last_seen. Used to group a list of
     * individual occurrences (e.g. notification sends) into one row per key
     * (e.g. notification class) with a real percentile, not just the
     * count/avg_duration/max_duration aggregateByKey() computes in SQL.
     * Sampled at high volume — see durationStats() — so `count` is exact
     * only up to DatabaseAggregateStorage::MAX_SAMPLE_ROWS matching rows.
     *
     * `$subtype` narrows the sample to one status, for types whose duration
     * only means something on a subset of their entries (a scheduled task's
     * `failed`/`skipped` rows carry no duration at all, so an unfiltered p95
     * would be diluted by them).
     */
    public function keyStats(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
        ?string $subtype = null,
    ): Collection;

    /**
     * The payload of the most recent entry of each key of a type, as
     * `key => payload array`. Used where a list row needs the task/job's
     * current *definition* (a scheduled task's cron expression, say) rather
     * than a statistic — the aggregate methods above never return payloads.
     * Exact, not sampled, but capped at `$limit` distinct keys.
     */
    public function latestPayloadByKey(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int $limit = 1000,
    ): Collection;
}
