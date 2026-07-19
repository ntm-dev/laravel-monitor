<?php

namespace LaravelMonitor\Livewire;

/**
 * Detail page for a single mail send (one specific message, not an aggregate
 * across many) — $key is the entry's own database id, matching
 * NotificationDetail. When this mail was triggered by a notification, looks
 * up that notification's entry via the shared correlation id so the page can
 * link back to it.
 */
class MailDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.mail-detail';
    }

    protected function data(): array
    {
        $storage = $this->storage();
        $entry = ctype_digit($this->key) ? $storage->findById((int) $this->key, 'mail') : null;

        $notification = null;
        $correlationId = $entry?->payload['correlation_id'] ?? null;

        if ($entry !== null && $correlationId !== null) {
            $notification = $storage->findByCorrelationId(
                'notification',
                $correlationId,
                $entry->created_at->subMinutes(5),
                $entry->created_at->addMinutes(5),
            );
        }

        return [
            'entry' => $entry,
            'notification' => $notification,
        ];
    }
}
