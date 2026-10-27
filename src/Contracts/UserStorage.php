<?php

namespace LaravelMonitor\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Per-user activity. "Sampled" means computed from a capped set of the most
 * recent matching rows, so counts are exact only below that cap.
 */
interface UserStorage
{
    /**
     * Distinct authenticated user_id count per time bucket, requests only. Sampled.
     *
     * @return int[]
     */
    public function authenticatedUserCountsPerBucket(
        DateTimeInterface $since,
        int $buckets = 40,
        ?DateTimeInterface $until = null,
    ): array;

    /**
     * Request counts per time bucket, split by whether a user_id was set. Sampled.
     *
     * @return array{authenticated: int[], guest: int[]}
     */
    public function requestAuthCountsPerBucket(
        DateTimeInterface $since,
        int $buckets = 40,
        ?DateTimeInterface $until = null,
    ): array;

    /**
     * Users with the most entries of a type: user_id, count (null $limit = no limit). Sampled.
     */
    public function topUsers(
        string $type,
        DateTimeInterface $since,
        ?int $limit = 10,
        ?DateTimeInterface $until = null,
    ): Collection;

    /**
     * One unsorted row per user_id: success (1/2/3xx), client_errors (4xx), server_errors (5xx),
     * requests, queued_jobs, exceptions, last_seen. Sampled across requests, jobs and exceptions combined.
     */
    public function userStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

    /**
     * Latest entry timestamp for a user across all types, or null.
     */
    public function lastSeenForUser(string $userId): ?CarbonImmutable;
}
