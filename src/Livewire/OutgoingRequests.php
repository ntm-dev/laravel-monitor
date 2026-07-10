<?php

namespace LaravelMonitor\Livewire;

class OutgoingRequests extends Card
{
    public function render()
    {
        $since = $this->since();
        $storage = $this->storage();

        $errors = $storage->aggregateByKey('outgoing_request', $since, 'error', 100)
            ->merge($storage->aggregateByKey('outgoing_request', $since, 'failed', 100))
            ->groupBy('key')
            ->map(fn ($groups) => $groups->sum('count'));

        return view('monitor::livewire.outgoing-requests', [
            'requests' => $storage->aggregateByKey('outgoing_request', $since, null, 10)
                ->map(function ($group) use ($errors) {
                    $group->errors = $errors->get($group->key, 0);

                    return $group;
                }),
        ]);
    }
}
