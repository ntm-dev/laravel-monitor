<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use LaravelMonitor\Support\Host;
use LaravelMonitor\Support\RecordType;
use Symfony\Component\Mime\Email;
use Throwable;

class Mail extends Recorder
{
    /** Stored bodies larger than this (chars) are truncated. */
    protected const MAX_BODY_CHARS = 10000;

    /**
     * When the current send started, set by MessageSending and read back by
     * MessageSent — same technique as CacheInteractions' before/after timing.
     */
    protected ?float $startedAt = null;

    public function register(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, function () {
            $this->startedAt = microtime(true);
        });
        $events->listen(MessageSent::class, [$this, 'record']);
    }

    public function record(MessageSent $event): void
    {
        // Monitor sends its own transactional mail for dashboard account
        // management (team invitations, password resets, email-change
        // verification) — never activity of the application being
        // monitored, so it must not be recorded as such, the same way
        // Recorders\Authentication excludes the dashboard's own guard.
        $mailableClass = $event->data['__laravel_mailable'] ?? null;

        if (is_string($mailableClass) && str_starts_with($mailableClass, 'LaravelMonitor\\')) {
            return;
        }

        $envelope = $this->envelope($event);
        $notification = is_string($event->data['__laravel_notification'] ?? null) ? $event->data['__laravel_notification'] : null;
        $mailable = is_string($mailableClass) ? $mailableClass : null;
        $body = ($this->config['details']['record_body'] ?? false) ? $this->body($event->message) : null;

        // round(x, 3): both operands are ~1.7-billion-magnitude Unix epoch
        // floats, so subtracting them is a floating-point catastrophic
        // cancellation — see Monitor::elapsedMsPrecise()'s own docs. 3
        // decimals matches microtime()'s own microsecond resolution.
        $duration = $this->startedAt !== null
            ? round((microtime(true) - $this->startedAt) * 1000, 3)
            : null;

        // Grouped by "type of email" — the notification/mailable class —
        // rather than the subject, so the dashboard list can show one row
        // per Mailable/notification (e.g. InvoiceMail) instead of one row
        // per send. Only ad-hoc mail with neither (Mail::raw(), a closure
        // view) falls back to the subject, since there's no class identity
        // to group by at all.
        $groupKey = $notification ?? $mailable ?? Str::limit($envelope['subject'], 250);

        $this->monitor->record(
            type: RecordType::Mail,
            key: $groupKey,
            payload: array_filter([
                'subject' => Str::limit($envelope['subject'], 250),
                'to' => Str::limit($envelope['to'], 250),
                'cc' => Str::limit($envelope['cc'], 250),
                'bcc' => Str::limit($envelope['bcc'], 250),
                'from' => Str::limit($envelope['from'], 250),
                'to_count' => $envelope['to_count'],
                'cc_count' => $envelope['cc_count'],
                'bcc_count' => $envelope['bcc_count'],
                'mailer' => $event->data['mailer'] ?? null,
                'mailable' => $mailable,
                'notification' => $notification,
                'attachments' => $envelope['attachments'],
                'attachment_names' => $envelope['attachment_names'],
                'correlation_id' => $notification !== null ? $this->monitor->pendingNotificationCorrelationId() : null,
                'body' => $body['content'] ?? null,
                'body_format' => $body['format'] ?? null,
                'server' => Host::name(),
            ]),
            duration: $duration,
            subtype: $notification !== null ? 'notification' : 'direct',
        );

        $this->startedAt = null;
    }

    /**
     * Subject, addresses and attachment names, or placeholders when the
     * message can't be read. Zero counts are left for array_filter() to drop.
     *
     * @return array{subject: string, to: string, cc: string, bcc: string, from: string, to_count: int, cc_count: int, bcc_count: int, attachments: int, attachment_names: list<string>}
     */
    protected function envelope(MessageSent $event): array
    {
        try {
            $message = $event->message;
            $addresses = static fn (array $recipients) => collect($recipients)->map(static fn ($address) => $address->getAddress())->implode(', ');

            return [
                'subject' => $message->getSubject() ?? '(no subject)',
                'to' => $addresses($message->getTo()),
                'cc' => $addresses($message->getCc()),
                'bcc' => $addresses($message->getBcc()),
                // "Name <email>": the sender is usually recognized by name.
                'from' => collect($message->getFrom())
                    ->map(static fn ($address) => $address->getName() !== '' ? $address->getName().' <'.$address->getAddress().'>' : $address->getAddress())
                    ->implode(', '),
                'to_count' => count($message->getTo()),
                'cc_count' => count($message->getCc()),
                'bcc_count' => count($message->getBcc()),
                'attachments' => count($message->getAttachments()),
                'attachment_names' => collect($message->getAttachments())
                    ->map(static fn ($part) => $part->getFilename())
                    ->filter()
                    ->values()
                    ->all(),
            ];
        } catch (Throwable) {
            return [
                'subject' => '(unknown)',
                'to' => '',
                'cc' => '',
                'bcc' => '',
                'from' => '',
                'to_count' => 0,
                'cc_count' => 0,
                'bcc_count' => 0,
                'attachments' => 0,
                'attachment_names' => [],
            ];
        }
    }

    /**
     * Best-effort rendered body (HTML preferred, text as a fallback), capped
     * in size — alongside its format, so the dashboard can render it back the
     * way the recipient's mail client did instead of guessing from content.
     *
     * @return array{format: 'html'|'text', content: string}|null
     */
    protected function body(Email $message): ?array
    {
        try {
            $html = $message->getHtmlBody();

            if (is_string($html)) {
                return ['format' => 'html', 'content' => Str::limit($html, self::MAX_BODY_CHARS)];
            }

            $text = $message->getTextBody();

            return is_string($text) ? ['format' => 'text', 'content' => Str::limit($text, self::MAX_BODY_CHARS)] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
