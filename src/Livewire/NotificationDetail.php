<?php

namespace LaravelMonitor\Livewire;

/**
 * Detail page for a single notification send (not an aggregate across many
 * sends, unlike JobDetail/QueryDetail/ExceptionDetail) — $key is the entry's
 * own database id. When it was sent over the mail channel, looks up the
 * `mail` entry the same send produced via the correlation id both recorders
 * stamp, so the page can link straight to it.
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
        $storage = $this->storage();
        $entry = ctype_digit($this->key) ? $storage->findById((int) $this->key, 'notification') : null;

        $mail = null;
        $correlationId = $entry?->payload['correlation_id'] ?? null;

        if ($entry !== null && $correlationId !== null) {
            // Search a tight window around this entry's own timestamp, not
            // the dashboard's selected period — a correlated pair is always
            // recorded moments apart, and the entry itself may sit outside
            // whatever range is currently selected.
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
