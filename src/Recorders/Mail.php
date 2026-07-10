<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use Throwable;

class Mail extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(MessageSent::class, [$this, 'record']);
    }

    public function record(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $subject = $message->getSubject() ?? '(no subject)';
            $to = collect($message->getTo())
                ->map(fn ($address) => $address->getAddress())
                ->implode(', ');
        } catch (Throwable) {
            $subject = '(unknown)';
            $to = '';
        }

        $mailable = $event->data['__laravel_notification'] ?? null;

        $this->monitor->record(
            type: 'mail',
            key: Str::limit($subject, 250),
            payload: array_filter([
                'subject' => Str::limit($subject, 250),
                'to' => Str::limit($to, 250),
                'notification' => is_string($mailable) ? $mailable : null,
            ]),
        );
    }
}
