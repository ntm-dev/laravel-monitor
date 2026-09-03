<?php

namespace LaravelMonitor\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

interface CacheAndQueryStorage
{
    /**
     * Per-key cache breakdown, unsorted: one row per key exposing key,
     * hit_ratio, hits, misses, writes, deletes, failures, total. Callers
     * sort/paginate themselves. Sampled at high volume — `total` and the
     * hit/miss/write/delete/failure tallies are exact only up to
     * maxSampleRows() matching rows.
     */
    public function cacheKeyStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

    /**
     * Per (query, connection) breakdown, unsorted: one row per pair exposing
     * key (the SQL), connection, calls, total, avg, p95. Callers sort/
     * paginate themselves. Sampled at high volume — `calls`/`total` are
     * exact only up to maxSampleRows() matching rows.
     */
    public function queryStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;
}
