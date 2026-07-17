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
     * Sampled at high volume — see DatabaseStorage::MAX_SAMPLE_ROWS — `count`
     * is exact only up to that many matching rows.
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
     * Totals for a type: object with count, avg_duration, max_duration, min_duration.
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
     * Entry counts split into $buckets equal time slices between $since and
     * $until (defaults to now) — used to draw activity charts.
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
     * user_id, count.
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
     * (distinct impacted users), first_seen and last_seen.
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
}
