<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

class Notifications extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(NotificationSent::class, [$this, 'record']);
    }

    public function record(NotificationSent $event): void
    {
        try {
            $notifiable = get_class($event->notifiable)
                .(method_exists($event->notifiable, 'getKey') ? '#'.$event->notifiable->getKey() : '');
        } catch (Throwable) {
            $notifiable = null;
        }

        $this->monitor->record(
            type: 'notification',
            key: get_class($event->notification),
            payload: array_filter([
                'notification' => get_class($event->notification),
                'channel' => $event->channel,
                'notifiable' => $notifiable,
            ]),
            subtype: $event->channel,
        );
    }
}
