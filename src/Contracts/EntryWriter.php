<?php

namespace LaravelMonitor\Contracts;

use DateTimeInterface;
use LaravelMonitor\Entry;

/**
 * Persists and purges raw entries — the write side every recorder/Monitor
 * flush() goes through, regardless of which page later reads them back.
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
