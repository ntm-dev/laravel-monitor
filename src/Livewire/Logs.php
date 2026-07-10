<?php

namespace LaravelMonitor\Livewire;

class Logs extends Card
{
    public string $level = '';

    public function render()
    {
        return view('monitor::livewire.logs', [
            'logs' => $this->storage()->recent('log', $this->since(), 20, $this->level !== '' ? $this->level : null),
        ]);
    }
}
