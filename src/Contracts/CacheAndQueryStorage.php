<?php

namespace LaravelMonitor\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

interface CacheAndQueryStorage
{
    /**
     * One unsorted row per cache key: key, hit_ratio, hits, misses, writes, deletes, failures, total.
     * Computed from a capped set of the most recent rows, so exact only below that cap.
     */
    public function cacheKeyStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

    /**
     * One unsorted row per (SQL, connection): key, connection, connection_types (every PDO role
     * seen), calls, total, avg, p95, last_seen. Sampled the same way as cacheKeyStats().
     */
    public function queryStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;
}
