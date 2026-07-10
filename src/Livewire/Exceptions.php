<?php

namespace LaravelMonitor\Livewire;

class Exceptions extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.exceptions';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();

        $groups = $this->storage()->aggregateByKey('exception', $since, null, $this->limit, 'last_seen', $until);

        // Attach the latest message/location for each exception class.
        $latest = $this->storage()
            ->recent('exception', $since, 100, null, null, $until)
            ->groupBy('key')
            ->map(fn ($entries) => $entries->first());

        return [
            'exceptions' => $groups->map(function ($group) use ($latest) {
                $group->latest = $latest->get($group->key)?->payload ?? [];

                return $group;
            }),
        ];
    }
}
