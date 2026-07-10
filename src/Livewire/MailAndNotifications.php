<?php

namespace LaravelMonitor\Livewire;

class MailAndNotifications extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.mail';
    }

    protected function data(): array
    {
        return [
            'mails' => $this->storage()->recent('mail', $this->since(), $this->limit, null, null, $this->until()),
        ];
    }
}
