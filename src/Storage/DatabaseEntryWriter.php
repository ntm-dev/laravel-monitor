<?php

namespace LaravelMonitor\Storage;

use DateTimeInterface;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\EntryWriter;
use LaravelMonitor\Storage\Concerns\BuildsQueries;

class DatabaseEntryWriter implements EntryWriter
{
    use BuildsQueries;

    public function store(array $entries): void
    {
        collect($entries)
            ->map(function ($entry) {
                $row = $entry->toArray();
                $row['payload'] = json_encode($row['payload']);
                // format('Y-m-d H:i:s.u'), not toDateTimeString(): the latter
                // always drops the fractional seconds CarbonImmutable::now()
                // already captured (see Entry::__construct()) — created_at(6)
                // in the migration exists specifically to keep them.
                $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s.u');

                return $row;
            })
            ->chunk(100)
            ->each(fn (Collection $chunk) => $this->table()->insert($chunk->all()));
    }

    public function purge(?DateTimeInterface $before = null): int
    {
        $query = $this->table();

        if ($before !== null) {
            $query->where('created_at', '<', $before);
        }

        return $query->delete();
    }
}
