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
     * $subtype accepts an array to match any of several subtypes at once
     * (e.g. every "ok" status group for a status filter tab). $minDuration
     * keeps only entries whose own duration is at or above it (a duration
     * filter tab).
     */
    public function recent(
        string $type,
        DateTimeInterface $since,
        int $limit = 50,
        string|array|null $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        int $offset = 0,
        ?float $minDuration = null,
    ): Collection;

    /**
     * Group entries by key. Each item exposes:
     * key, count, avg_duration, max_duration, last_seen, users (distinct
     * user_id count).
     *
     * $orderBy is one of: count, avg_duration, max_duration, last_seen.
     * Sampled at high volume — see durationStats() — `count`/`users` are
     * exact only up to DatabaseStorage::MAX_SAMPLE_ROWS matching rows.
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
     * min_duration, total_duration. $subtype accepts an array the same way
     * recent() does. $minDuration restricts to entries at or above it (a
     * duration filter tab's own badge count).
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
        int|string|null $userId = null,
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
        int|string|null $userId = null,
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
     * key, methods, count, success, client_errors, server_errors, avg_duration, p95_duration.
     * For type 'request', every entry whose key ends in " Recorders\Requests::UNMATCHED_ROUTE"
     * (no matched Laravel route) is merged into a single row keyed by the bare
     * Requests::UNMATCHED_ROUTE sentinel, with `methods` listing the distinct
     * HTTP methods behind it (null for every ordinary route).
     * Sampled at high volume — see durationStats() — so `count` and the
     * error breakdowns are exact only up to DatabaseStorage::MAX_SAMPLE_ROWS
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
     * only up to DatabaseStorage::MAX_SAMPLE_ROWS matching rows.
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
        int|string|null $userId = null,
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
     * The 'queued' dispatch-time entry sharing the given job_id (the queue
     * driver's own id — see Recorders\Jobs), or null when none match within
     * the window — the reverse of jobExecutionsByJobId(): given a job
     * attempt's own outcome (which carries the same job_id in its payload),
     * find what dispatched it. That entry's own request_id/type (via
     * rootTypesFor()) identifies the request/command/scheduled task it came
     * from, if any.
     */
    public function findQueuedJobByJobId(string $jobId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object;

    /**
     * For each given job_id, every outcome entry (processed/failed/released
     * — more than one on a retry) recorded for it, each paired with its own
     * children (queries/mail/... it triggered while running) — the data
     * needed to splice a dispatched job's own execution into the timeline of
     * whatever request/command/scheduled task dispatched it, producing a
     * single merged trace view instead of a bare, dead-end
     * "queued" placeholder. Keyed by job_id; a job_id with no matching
     * outcome yet is simply absent. Each job_id's own outcomes are ordered
     * oldest-first — MergesJobTimelines::jobTrack() numbers "Attempt #N"
     * purely by position in this collection, so an unordered implementation
     * would risk mislabeling which retry is which.
     *
     * @param  string[]  $jobIds
     * @return Collection<string, Collection<int, object{outcome: object, children: Collection}>>
     */
    public function jobExecutionsByJobId(array $jobIds, DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

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
     * Which root type ('request', 'job', or 'command') each of the given
     * correlation ids belongs to, keyed by request_id, in a single query —
     * batches what would otherwise be one findByRequestId() probe per row
     * (e.g. deciding whether a list row should link to the Request, Job
     * Attempt, or Command Run timeline).
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function rootTypesFor(array $requestIds): Collection;

    /**
     * The root entry's own key ("METHOD /path" for a request, the job class
     * name, or the artisan command string) for each of the given correlation
     * ids, keyed by request_id, in a single query — the generic counterpart
     * to requestLabels() that doesn't assume the root is a request. Pair
     * with rootTypesFor() to know which of the three it is before deciding
     * which detail-page route to link to.
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function rootLabelsFor(array $requestIds): Collection;

    /**
     * Record that each of the given issues (an exception group or a
     * performance-threshold breach) is still occurring, as of its own last
     * occurrence in this period — creates a new "open" row on first sight,
     * otherwise just bumps last_seen. A previously "resolved" issue that
     * recurs after its resolved_at reopens automatically; an "ignored" issue
     * stays ignored until manually reopened.
     *
     * @param  array<string, DateTimeInterface>  $lastSeenByKey
     */
    public function syncIssues(string $type, array $lastSeenByKey): void;

    /**
     * Delete every currently-"open" issue of $type whose key is absent
     * from $currentKeys — syncIssues()'s complement: syncIssues() only
     * ever opens/bumps/reopens, so without this an issue stays "open"
     * forever once nothing keeps recording it (a performance key that's
     * dropped back under its threshold, or all its underlying entries have
     * been pruned) even though openIssueCount() keeps counting it and it
     * never shows up on the page again. An "ignored"/"resolved" issue is
     * left alone — only a stuck-"open" one with nothing behind it anymore
     * gets removed. An empty $currentKeys deletes every open issue of
     * $type. Returns the number of issues deleted.
     *
     * @param  string[]  $currentKeys
     */
    public function deleteMissingIssues(string $type, array $currentKeys): int;

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
     * Delete every "open" issue whose (type, key) no longer matches any row
     * in monitor_entries — called by PruneCommand right after it purges
     * old entries, so an issue never sits "open" forever once the raw data
     * proving it recurred is gone. Checked by actual existence rather than
     * comparing last_seen against the prune cutoff: a key can predate that
     * cutoff's data even while last_seen itself still looks recent, e.g.
     * after an earlier prune ran with a shorter --hours value. Returns the
     * number of issues deleted.
     */
    public function expireStaleIssues(): int;

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

    /**
     * The original key whose KeyHash::for() hash matches $hash, among every
     * entry of $type ever recorded — the hash is one-way, so this scans the
     * type's distinct keys (index-only via the [type, key] index) for a
     * match. Null when nothing matches (a stale/invalid hash).
     */
    public function resolveKeyHash(string $type, string $hash): ?string;
}
