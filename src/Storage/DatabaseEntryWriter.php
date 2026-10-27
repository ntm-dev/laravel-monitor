<?php

namespace LaravelMonitor\Storage;

use DateTimeInterface;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\EntryWriter;
use LaravelMonitor\Storage\Concerns\BuildsQueries;
use LaravelMonitor\Support\Json;

class DatabaseEntryWriter implements EntryWriter
{
    use BuildsQueries;

    protected const PURGE_BATCH_SIZE = 5000;

    public function store(array $entries): void
    {
        collect($entries)
            ->map(function ($entry) {
                $row = $entry->toArray();
                $row['payload'] = Json::encode($row['payload']);
                // Keep microseconds for the timestamp(6) created_at column.
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s.u');

                return $row;
            })
            ->chunk(100)
            ->each(fn (Collection $chunk) => $this->table()->insert($chunk->all()));
    }

    public function purge(?DateTimeInterface $before = null): int
    {
        $total = 0;

        // Batched: one huge DELETE can fail with MySQL 1206 (lock table size exceeded).
        do {
            $query = $this->table();

            if ($before !== null) {
                $query->where('created_at', '<', $before);
            }

            $deleted = $query->limit(self::PURGE_BATCH_SIZE)->delete();
            $total += $deleted;
        } while ($deleted >= self::PURGE_BATCH_SIZE);

        return $total;
    }
}
