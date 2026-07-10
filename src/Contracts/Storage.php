<?php

namespace LaravelMonitor\Contracts;

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
    ): Collection;

    /**
     * Group entries by key. Each item exposes:
     * key, count, avg_duration, max_duration, last_seen.
     *
     * $orderBy is one of: count, avg_duration, max_duration, last_seen.
     */
    public function aggregateByKey(
        string $type,
        DateTimeInterface $since,
        ?string $subtype = null,
        int $limit = 10,
        string $orderBy = 'count',
    ): Collection;

    /**
     * Totals for a type: object with count, avg_duration, max_duration.
     */
    public function stats(string $type, DateTimeInterface $since, ?string $subtype = null): object;

    /**
     * Entry counts split into $buckets equal time slices between $since and
     * now — used to draw activity sparklines.
     *
     * @return int[]
     */
    public function countsPerBucket(
        string $type,
        DateTimeInterface $since,
        int $buckets = 40,
        ?string $subtype = null,
    ): array;

    /**
     * Users generating the most entries of a type. Each item exposes:
     * user_id, count.
     */
    public function topUsers(string $type, DateTimeInterface $since, int $limit = 10): Collection;
}
