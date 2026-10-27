<?php

namespace LaravelMonitor\Contracts;

use DateTimeInterface;
use LaravelMonitor\Entry;

/**
 * Write side for raw entries: persist and purge.
 */
interface EntryWriter
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
}
