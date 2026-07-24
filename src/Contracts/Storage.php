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
        int $offset = 0,
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
     * Generic per-key breakdown for a type with no status/subtype dimension
     * to split by (unlike routeStats()) — one item per key exposing key,
     * count, avg_duration, p95_duration, last_seen. Used to group a list of
     * individual occurrences (e.g. notification sends) into one row per key
     * (e.g. notification class) with a real percentile, not just the
     * count/avg_duration/max_duration aggregateByKey() computes in SQL.
     * Sampled at high volume — see durationStats() — so `count` is exact
     * only up to DatabaseStorage::MAX_SAMPLE_ROWS matching rows.
     */
    public function keyStats(
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
     * The root entry (type $rootType — 'request' or 'job') recorded with the
     * given correlation id, or null when unknown. Exposes the same fields as
     * recent() rows plus request_id and start_offset.
     */
    public function findByRequestId(string $requestId, string $rootType = 'request'): ?object;

    /**
     * A single entry by its own primary key, scoped to $type, or null when
     * unknown — for a detail page about one specific occurrence (e.g. one
     * notification send) rather than an aggregate across many. Same row
     * shape as recent().
     */
    public function findById(int $id, string $type): ?object;

    /**
     * The first entry of $type whose payload has `correlation_id` equal to
     * $correlationId, or null when none match — links a mail-channel
     * notification's entry to the `mail` entry its send produced (and back).
     * Scans only entries of $type within $since/$until, since a correlated
     * pair is always recorded moments apart.
     */
    public function findByCorrelationId(string $type, string $correlationId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object;

    /**
     * Every entry correlated to the given request/job attempt (excluding the
     * root entry itself, type $rootType), ordered by where it started on the
     * timeline. Same row shape as findByRequestId().
     */
    public function timelineFor(string $requestId, string $rootType = 'request'): Collection;

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

    /**
     * Which root type ('request' or 'job') each of the given correlation ids
     * belongs to, keyed by request_id, in a single query — batches what
     * would otherwise be one findByRequestId() probe per row (e.g. deciding
     * whether a notification/mail list row should link to the Request or
     * Job Attempt timeline).
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function rootTypesFor(array $requestIds): Collection;

    /**
     * Record that each of the given issues (an exception group or a
     * performance-threshold breach) is still occurring, as of its own last
     * occurrence in this period — creates a new "open" row on first sight,
     * otherwise just bumps last_seen. A previously "resolved" issue that
     * recurs after its resolved_at reopens automatically (mirrors
     * Nightwatch); an "ignored" issue stays ignored until manually reopened.
     *
     * @param  array<string, DateTimeInterface>  $lastSeenByKey
     */
    public function syncIssues(string $type, array $lastSeenByKey): void;

    /**
     * Status + priority + first_seen for each of the given keys of a type,
     * keyed by key — batches what would otherwise be one lookup per row on
     * the Issues page. A key with no matching row (not yet synced) is
     * simply absent.
     *
     * @param  string[]  $keys
     * @return Collection<string, object{id: int, uuid: string, status: string, priority: string, first_seen: CarbonImmutable}>
     */
    public function issueStatuses(string $type, array $keys): Collection;

    /**
     * Set an issue's status directly (open/resolved/ignored) — the resolve/
     * ignore/reopen actions on the Issues page. Creates the row if
     * syncIssues() hasn't seen this key yet rather than silently no-op-ing.
     */
    public function setIssueStatus(string $type, string $key, string $status): void;

    /**
     * Count of issues currently "open" — powers the sidebar badge. Not
     * scoped to the viewer's selected time range: issues are persistent
     * records synced by syncIssues(), not a windowed event count.
     */
    public function openIssueCount(): int;

    /**
     * Set an issue's priority (one of Format::PRIORITIES' keys) — silently
     * no-ops on an invalid value. Creates the row if syncIssues() hasn't
     * seen this key yet, same as setIssueStatus().
     */
    public function setIssuePriority(string $type, string $key, string $priority): void;

    /**
     * Resolve a monitor_issues row by its uuid — the /monitor/issues/{uuid}
     * detail route uses this to find the [type, key] pair to fetch the
     * underlying exception/performance data for.
     */
    public function findIssueByUuid(string $uuid): ?object;
}
