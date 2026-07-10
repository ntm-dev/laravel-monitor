<?php

namespace LaravelMonitor\Livewire;

class MailAndNotifications extends Card
{
    public function render()
    {
        $since = $this->since();
        $storage = $this->storage();

        return view('monitor::livewire.mail', [
            'mails' => $storage->recent('mail', $since, $this->limit),
            'notifications' => $storage->aggregateByKey('notification', $since, null, $this->limit),
        ]);
    }
}
