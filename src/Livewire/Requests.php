<?php

namespace LaravelMonitor\Livewire;

class Requests extends Card
{
    public string $orderBy = 'count';

    public function render()
    {
        return view('monitor::livewire.requests', [
            'routes' => $this->storage()->aggregateByKey('request', $this->since(), null, $this->limit, $this->orderBy),
        ]);
    }
}
