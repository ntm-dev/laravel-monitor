<?php

namespace LaravelMonitor\Livewire;

class MailAndNotifications extends Card
{
    public function render()
    {
        $since = $this->since();
        $storage = $this->storage();

        return view('monitor::livewire.mail', [
            'mails' => $storage->recent('mail', $since, 8),
            'notifications' => $storage->aggregateByKey('notification', $since, null, 8),
        ]);
    }
}
