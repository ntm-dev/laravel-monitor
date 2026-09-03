<?php

namespace LaravelMonitor\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Per-user activity: the Users tab's own charts/list, and the User Detail
 * page's "last seen".
 */
interface UserStorage
{
    /**
     * Distinct authenticated user_id count per time bucket, requests only —
     * the "Authenticated Users" chart on the Users tab. Sampled at high
     * volume — exact only up to maxSampleRows()
     * matching rows.
     *
     * @return int[]
     */
    public function authenticatedUserCountsPerBucket(
        DateTimeInterface $since,
        int $buckets = 40,
        ?DateTimeInterface $until = null,
    ): array;

    /**
     * Request counts per time bucket split by whether the request carried a
     * user_id — the "Requests" chart on the Users tab (Authenticated vs
     * Guest). Sampled at high volume — exact only up to
     * maxSampleRows() matching rows.
     *
     * @return array{authenticated: int[], guest: int[]}
     */
    public function requestAuthCountsPerBucket(
        DateTimeInterface $since,
        int $buckets = 40,
        ?DateTimeInterface $until = null,
    ): array;

    /**
     * Users generating the most entries of a type. Each item exposes:
     * user_id, count. Sampled at high volume — `count` is exact only up to
     * maxSampleRows() matching rows.
     */
    public function topUsers(
        string $type,
        DateTimeInterface $since,
        int $limit = 10,
        ?DateTimeInterface $until = null,
    ): Collection;

    /**
     * Per-user activity for the Users tab list: one row per identified
     * user_id exposing user_id, success (1/2/3xx request count),
     * client_errors (4xx), server_errors (5xx), requests (total request
     * count), queued_jobs, exceptions, last_seen. Unsorted — callers sort/
     * paginate themselves. Sampled at high volume — so the counts are exact
     * only up to maxSampleRows() matching rows
     * across request + job + exception combined.
     */
    public function userStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

    /**
     * Most recent activity timestamp for a user across every entry type —
     * the User Detail page's own "last seen", not scoped to one type the way
     * topUsers()/userStats() are. Null when the user has no recorded
     * activity at all.
     */
    public function lastSeenForUser(string $userId): ?CarbonImmutable;
}
