<?php

namespace LaravelMonitor\Livewire;

/**
 * Detail page for a single outgoing (HTTP client) request — one specific
 * call, not an aggregate across many, matching NotificationDetail/MailDetail.
 * $key is the entry's own database id. Unlike those two, outgoing requests
 * aren't correlated to another recorder's entry, so there's nothing else to
 * look up here.
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
        $entry = ctype_digit($this->key) ? $this->timelineStorage()->findById((int) $this->key, 'outgoing_request') : null;

        return [
            'entry' => $entry,
        ];
    }
}
