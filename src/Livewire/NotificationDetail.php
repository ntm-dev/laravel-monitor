<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Support\EntryId;

/**
 * A single notification send; $key is the EntryId-encoded row id. Links to
 * the `mail` entry the same send produced via the shared correlation id.
 */
class NotificationDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.notification-detail';
    }

    protected function data(): array
    {
        $storage = $this->timelineStorage();
        $id = EntryId::decode($this->key);
        $entry = $id !== null ? $storage->findById($id, 'notification') : null;

        $mail = null;
        $correlationId = $entry?->payload['correlation_id'] ?? null;

        if ($entry !== null && $correlationId !== null) {
            // ±5 minutes around this entry, not the selected period.
            $mail = $storage->findByCorrelationId(
                'mail',
                $correlationId,
                $entry->created_at->subMinutes(5),
                $entry->created_at->addMinutes(5),
            );
        }

        return [
            'entry' => $entry,
            'mail' => $mail,
        ];
    }
}
