<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use LaravelMonitor\Support\RecordType;
use Throwable;

use function is_string;
use function json_encode;
use function method_exists;
use function strlen;

class Notifications extends Recorder
{
    /** Stored data larger than this (encoded, in bytes) is dropped in favor of a size marker. */
    protected const MAX_DATA_BYTES = 10000;

    /** Set by NotificationSending, read by NotificationSent. */
    protected ?float $startedAt = null;

    public function register(Dispatcher $events): void
    {
        $events->listen(NotificationSending::class, [$this, 'sending']);
        $events->listen(NotificationSent::class, [$this, 'record']);
    }

    public function sending(NotificationSending $event): void
    {
        $this->startedAt = microtime(true);

        // Correlation id shared with the Mail recorder's entry for this send.
        if ($event->channel === 'mail') {
            $this->monitor->beginNotificationDispatch();
        }
    }

    public function record(NotificationSent $event): void
    {
        try {
            $notifiable = get_class($event->notifiable)
                .(method_exists($event->notifiable, 'getKey') ? '#'.$event->notifiable->getKey() : '');
        } catch (Throwable) {
            $notifiable = null;
        }

        // round(x, 3) strips float noise from subtracting two epoch timestamps.
        $duration = $this->startedAt !== null
            ? round((microtime(true) - $this->startedAt) * 1000, 3)
            : null;

        $this->monitor->record(
            type: RecordType::Notification,
            key: get_class($event->notification),
            payload: array_filter([
                'notification' => get_class($event->notification),
                'channel' => $event->channel,
                'notifiable' => $notifiable,
                'correlation_id' => $event->channel === 'mail' ? $this->monitor->pendingNotificationCorrelationId() : null,
                'data' => ($this->config['details']['record_data'] ?? false) ? $this->data($event) : null,
            ]),
            duration: $duration,
            subtype: $event->channel,
        );

        if ($event->channel === 'mail') {
            $this->monitor->endNotificationDispatch();
        }

        $this->startedAt = null;
    }

    /**
     * The notification's toArray(), if it has one, as a channel-agnostic
     * payload. Over MAX_DATA_BYTES it becomes a size marker.
     */
    protected function data(NotificationSent $event): mixed
    {
        if (! method_exists($event->notification, 'toArray')) {
            return null;
        }

        try {
            $data = $event->notification->toArray($event->notifiable);
        } catch (Throwable) {
            return null;
        }

        $encoded = json_encode($data);

        if (! is_string($encoded) || strlen($encoded) > self::MAX_DATA_BYTES) {
            return ['_truncated' => true, '_size' => is_string($encoded) ? strlen($encoded) : null];
        }

        return $data;
    }
}
