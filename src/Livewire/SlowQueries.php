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
            // SlowQueries now persists every query executed inside a request
            // (not just slow ones) so the Request Detail timeline can show
            // them all — filter to `slow` here so this digest still only
            // lists queries over the configured threshold.
            'queries' => $this->storage()->aggregateByKey('slow_query', $this->since(), 'slow', $this->limit, 'max_duration', $this->until()),
        ];
    }
}
