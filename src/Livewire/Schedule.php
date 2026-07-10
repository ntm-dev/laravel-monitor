<?php

namespace LaravelMonitor\Livewire;

class Schedule extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.schedule';
    }

    protected function data(): array
    {
        return [
            'tasks' => $this->storage()->recent('scheduled_task', $this->since(), $this->limit, null, null, $this->until()),
        ];
    }
}
