<?php

namespace LaravelMonitor\Livewire;

class CacheStats extends Card
{
    public function render()
    {
        $since = $this->since();
        $storage = $this->storage();

        $hits = $storage->stats('cache', $since, 'hit')->count;
        $misses = $storage->stats('cache', $since, 'miss')->count;
        $total = $hits + $misses;

        return view('monitor::livewire.cache', [
            'hits' => $hits,
            'misses' => $misses,
            'writes' => $storage->stats('cache', $since, 'write')->count,
            'hitRate' => $total > 0 ? round($hits / $total * 100, 1) : null,
            'keys' => $storage->aggregateByKey('cache', $since, null, $this->limit),
        ]);
    }
}
