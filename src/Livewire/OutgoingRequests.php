<?php

namespace LaravelMonitor\Livewire;

class OutgoingRequests extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.outgoing-requests';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();

        $errors = $storage->aggregateByKey('outgoing_request', $since, 'error', 100, 'count', $until)
            ->keyBy('key');

        return [
            'requests' => $storage->aggregateByKey('outgoing_request', $since, null, $this->limit, 'count', $until)
                ->map(function ($request) use ($errors) {
                    $request->errors = $errors->get($request->key)?->count ?? 0;

                    return $request;
                }),
            'threshold' => (int) config('monitor.thresholds.outgoing_request', 1000),
        ];
    }
}
