<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Support\EntryId;

/**
 * A single outgoing HTTP request; $key is the EntryId-encoded row id.
 */
class OutgoingDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.outgoing-detail';
    }

    protected function data(): array
    {
        $id = EntryId::decode($this->key);
        $entry = $id !== null ? $this->timelineStorage()->findById($id, 'outgoing_request') : null;

        return [
            'entry' => $entry,
        ];
    }
}
