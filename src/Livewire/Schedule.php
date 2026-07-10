<?php

namespace LaravelMonitor\Livewire;

class Schedule extends Card
{
    public function render()
    {
        return view('monitor::livewire.schedule', [
            'tasks' => $this->storage()->recent('scheduled_task', $this->since(), 15),
        ]);
    }
}
