<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Str;
use LaravelMonitor\Support\EntryId;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A single mail send; $key is the EntryId-encoded row id. Links to the
 * notification that sent it via the shared correlation id.
 */
class MailDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    /**
     * Best-effort .eml rebuilt from the recorded fields; attachment content
     * isn't recorded.
     */
    public function downloadEml(): StreamedResponse
    {
        $entry = $this->entry();

        abort_if($entry === null, 404);

        $payload = $entry->payload;
        $subject = $payload['subject'] ?? $entry->key;
        $format = ($payload['body_format'] ?? null) === 'html' ? 'text/html' : 'text/plain';

        $headers = array_filter([
            'Date' => $entry->created_at->toRfc2822String(),
            'From' => $payload['from'] ?? null,
            'To' => $payload['to'] ?? null,
            'Cc' => $payload['cc'] ?? null,
            'Subject' => mb_encode_mimeheader($subject, 'UTF-8'),
            'MIME-Version' => '1.0',
            'Content-Type' => $format.'; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ]);

        $eml = collect($headers)->map(fn ($value, $name) => "{$name}: {$value}")->implode("\r\n")
            ."\r\n\r\n".($payload['body'] ?? '');

        // Strip filesystem-illegal characters; streamDownload() encodes non-ASCII.
        $filename = trim((string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/', '_', $subject));

        return response()->streamDownload(
            fn () => print($eml),
            Str::limit($filename !== '' ? $filename : 'mail', 100, '').'.eml',
            ['Content-Type' => 'message/rfc822'],
        );
    }

    protected function view(): string
    {
        return 'monitor::livewire.mail-detail';
    }

    protected function entry(): ?object
    {
        $id = EntryId::decode($this->key);

        return $id !== null ? $this->timelineStorage()->findById($id, 'mail') : null;
    }

    protected function data(): array
    {
        $storage = $this->timelineStorage();
        $entry = $this->entry();

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
