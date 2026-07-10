<?php

namespace LaravelMonitor\Livewire;

class Exceptions extends Card
{
    public function render()
    {
        $since = $this->since();

        $groups = $this->storage()->aggregateByKey('exception', $since, null, 10, 'last_seen');

        // Attach the latest message/location for each exception class.
        $latest = $this->storage()
            ->recent('exception', $since, 100)
            ->groupBy('key')
            ->map(fn ($entries) => $entries->first());

        return view('monitor::livewire.exceptions', [
            'exceptions' => $groups->map(function ($group) use ($latest) {
                $group->latest = $latest->get($group->key)?->payload ?? [];

                return $group;
            }),
        ]);
    }
}
