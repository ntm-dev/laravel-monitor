<?php

namespace LaravelMonitor\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Generic per-key/per-bucket/per-subtype aggregates behind the list and chart cards.
 * "Sampled" below means computed from a capped set of the most recent matching rows,
 * so counts are exact only below that cap.
 */
interface AggregateStorage
{
    /**
     * Entries grouped by key: key, count, avg_duration, max_duration, last_seen,
     * users (distinct user_id count). $orderBy: count|avg_duration|max_duration|last_seen
     * (anything else falls back to count). Sampled.
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
     * Exact totals: count, avg_duration, max_duration, min_duration, total_duration.
     * $subtype may be an array (match any); $minDuration keeps entries at or above it.
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
     * stats() for every subtype at once, keyed by subtype; subtypes with no entries are absent.
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
     * Entry counts in $buckets equal time slices from $since to $until (default now).
     * May be sampled.
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
     * Duration distribution: min, max, avg, p95, plus avg_per_bucket/p95_per_bucket
     * (float|null per slice). Sampled, so an approximation at high volume.
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
     * Per-key breakdown: key, methods, count, success, client_errors, server_errors,
     * network_errors, avg_duration, p95_duration, max_duration, last_seen. For 'request', all
     * unmatched-route keys merge into one Requests::UNMATCHED_ROUTE row whose `methods` lists
     * their HTTP methods (null on other rows). Sampled.
     */
    public function routeStats(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
    ): Collection;

    /**
     * Per-key breakdown without a status split: key, count, avg_duration, p95_duration,
     * last_seen. $subtype narrows to one status (e.g. only entries that carry a duration). Sampled.
     */
    public function keyStats(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
        ?string $subtype = null,
    ): Collection;

    /**
     * Payload of each key's most recent entry, as `key => payload array`.
     * Not sampled, but capped at $limit keys.
     */
    public function latestPayloadByKey(
        string $type,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int $limit = 1000,
    ): Collection;
}
