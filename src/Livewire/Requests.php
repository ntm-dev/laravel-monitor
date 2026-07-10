<?php

namespace LaravelMonitor\Livewire;

class Requests extends Card
{
    public string $orderBy = 'count';

    protected function view(): string
    {
        return 'monitor::livewire.requests';
    }

    protected function data(): array
    {
        return [
            'routes' => $this->storage()->aggregateByKey('request', $this->since(), null, $this->limit, $this->orderBy, $this->until()),
            'threshold' => (int) config('monitor.thresholds.request', 1000),
        ];
    }
}
