<?php

namespace LaravelMonitor\Livewire;

class SlowQueries extends Card
{
    public function render()
    {
        return view('monitor::livewire.slow-queries', [
            'queries' => $this->storage()->aggregateByKey('slow_query', $this->since(), null, $this->limit, 'max_duration'),
        ]);
    }
}
