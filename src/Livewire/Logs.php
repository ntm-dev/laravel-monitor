<?php

namespace LaravelMonitor\Livewire;

class Logs extends Card
{
    public string $level = '';

    protected function view(): string
    {
        return 'monitor::livewire.logs';
    }

    protected function data(): array
    {
        return [
            'logs' => $this->storage()->recent('log', $this->since(), $this->limit, $this->level ?: null, null, $this->until()),
        ];
    }
}
