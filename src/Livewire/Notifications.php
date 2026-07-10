<?php

namespace LaravelMonitor\Livewire;

class Notifications extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.notifications';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();

        return [
            'notifications' => $storage->aggregateByKey('notification', $since, null, $this->limit, 'count', $until),
            'recent' => $storage->recent('notification', $since, $this->limit, null, null, $until),
        ];
    }
}
