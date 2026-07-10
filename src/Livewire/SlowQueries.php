<?php

namespace LaravelMonitor\Livewire;

class SlowQueries extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.slow-queries';
    }

    protected function data(): array
    {
        return [
            'queries' => $this->storage()->aggregateByKey('slow_query', $this->since(), null, $this->limit, 'max_duration', $this->until()),
        ];
    }
}
