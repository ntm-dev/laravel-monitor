<?php

namespace LaravelMonitor\Livewire;

class Logs extends Card
{
    public string $level = '';

    public function render()
    {
        return view('monitor::livewire.logs', [
            'logs' => $this->storage()->recent('log', $this->since(), $this->limit, $this->level !== '' ? $this->level : null),
        ]);
    }
}
