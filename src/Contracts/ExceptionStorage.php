<?php

namespace LaravelMonitor\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

interface ExceptionStorage
{
    /**
     * One row per fingerprint key: key, class, message, file, line (from the latest occurrence),
     * count, handled, unhandled, users (distinct), first_seen, last_seen.
     * Computed from a capped set of the most recent rows, so exact only below that cap.
     */
    public function exceptionGroups(
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int|string|null $userId = null,
    ): Collection;
}
