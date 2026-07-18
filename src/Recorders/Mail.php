<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use Throwable;

class Mail extends Recorder
{
    /**
     * When the current send started, set by MessageSending and read back by
     * MessageSent — same technique as CacheInteractions' before/after timing.
     */
    protected ?float $startedAt = null;

    public function register(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, fn () => $this->startedAt = microtime(true));
        $events->listen(MessageSent::class, [$this, 'record']);
    }

    public function record(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $subject = $message->getSubject() ?? '(no subject)';
            $addresses = fn (array $recipients) => collect($recipients)->map(fn ($address) => $address->getAddress())->implode(', ');
            $to = $addresses($message->getTo());
            $cc = $addresses($message->getCc());
            $bcc = $addresses($message->getBcc());
            $attachments = count($message->getAttachments());
        } catch (Throwable) {
            $subject = '(unknown)';
            $to = '';
            $cc = '';
            $bcc = '';
            $attachments = 0;
        }

        $notification = $event->data['__laravel_notification'] ?? null;
        $mailable = $event->data['__laravel_mailable'] ?? null;

        $duration = $this->startedAt !== null
            ? round((microtime(true) - $this->startedAt) * 1000, 3)
            : null;

        // Grouped by "type of email" — the notification/mailable class —
        // rather than the subject, so the dashboard list can show one row
        // per Mailable/notification (e.g. InvoiceMail) instead of one row
        // per send. Only ad-hoc mail with neither (Mail::raw(), a closure
        // view) falls back to the subject, since there's no class identity
        // to group by at all.
        $groupKey = is_string($notification) ? $notification : (is_string($mailable) ? $mailable : Str::limit($subject, 250));

        $this->monitor->record(
            type: 'mail',
            key: $groupKey,
            payload: array_filter([
                'subject' => Str::limit($subject, 250),
                'to' => Str::limit($to, 250),
                'cc' => Str::limit($cc, 250),
                'bcc' => Str::limit($bcc, 250),
                'mailer' => $event->data['mailer'] ?? null,
                'mailable' => is_string($mailable) ? $mailable : null,
                'notification' => is_string($notification) ? $notification : null,
                'attachments' => $attachments > 0 ? $attachments : null,
                'correlation_id' => is_string($notification) ? $this->monitor->pendingNotificationCorrelationId() : null,
            ]),
            duration: $duration,
            subtype: is_string($notification) ? 'notification' : 'direct',
        );

        $this->startedAt = null;
    }
}
