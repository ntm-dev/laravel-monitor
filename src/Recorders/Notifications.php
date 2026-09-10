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

    /**
     * When the current channel's send started, set by NotificationSending
     * and read back by NotificationSent — same technique as
     * CacheInteractions' before/after timing.
     */
    protected ?float $startedAt = null;

    public function register(Dispatcher $events): void
    {
        $events->listen(NotificationSending::class, [$this, 'sending']);
        $events->listen(NotificationSent::class, [$this, 'record']);
    }

    public function sending(NotificationSending $event): void
    {
        $this->startedAt = microtime(true);

        // A mail-channel send fires its own MessageSending/MessageSent
        // around this one; stamping a correlation id here (read back by
        // both this recorder and Mail's) is how the two entries end up
        // linkable on the dashboard despite being recorded independently.
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

        // round(x, 3): both operands are ~1.7-billion-magnitude Unix epoch
        // floats, so subtracting them is a floating-point catastrophic
        // cancellation — see Monitor::elapsedMsPrecise()'s own docs. 3
        // decimals matches microtime()'s own microsecond resolution.
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
     * Best-effort generic representation of what was sent — toArray() is
     * what the 'database' channel itself renders to, so it's the closest
     * thing to a channel-agnostic payload every notification already
     * exposes. Not every notification implements it; capped in size like
     * Recorders\Requests::body().
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
