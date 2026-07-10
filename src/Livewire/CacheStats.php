<?php

namespace LaravelMonitor\Livewire;

class CacheStats extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.cache';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();

        $hits = $storage->stats('cache', $since, 'hit', null, $until)->count;
        $misses = $storage->stats('cache', $since, 'miss', null, $until)->count;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'writes' => $storage->stats('cache', $since, 'write', null, $until)->count,
            'hitRate' => ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100) : null,
            'keys' => $storage->aggregateByKey('cache', $since, null, $this->limit, 'count', $until),
        ];
    }
}
