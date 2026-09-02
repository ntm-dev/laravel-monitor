<?php

namespace LaravelMonitor\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

interface ExceptionStorage
{
    /**
     * Grouped exceptions: one item per fingerprint key exposing
     * key, class, message, file, line, count, handled, unhandled, users
     * (distinct impacted users), first_seen and last_seen. Sampled at high
     * volume — `count` and the handled/unhandled/users tallies are exact
     * only up to DatabaseAggregateStorage::MAX_SAMPLE_ROWS matching rows.
     */
    public function exceptionGroups(
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
    ): Collection;
}
