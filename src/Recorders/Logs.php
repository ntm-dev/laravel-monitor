<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Types\Str;
use Throwable;

use function in_array;
use function is_object;
use function is_resource;

class Logs extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, [$this, 'record']);
    }

    public function record(MessageLogged $event): void
    {
        // Exceptions have their own recorder.
        if ($this->shouldIgnore() || ($event->context['exception'] ?? null) instanceof Throwable) {
            return;
        }

        $levels = $this->config['levels'] ?? ['emergency', 'alert', 'critical', 'error', 'warning'];

        if (! in_array($event->level, $levels, true)) {
            return;
        }

        $context = collect($event->context)
            ->reject(fn ($value) => is_object($value) || is_resource($value))
            ->all();

        $message = (string) $event->message;

        $this->monitor->record(
            type: RecordType::Log,
            key: Str::tinyText($message),
            payload: [
                'message' => Str::text($message),
                'context' => Str::mediumText(json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '{}'),
            ],
            subtype: $event->level,
            userId: $this->monitor->currentUserId(),
        );
    }

    /**
     * MessageLogged carries no request of its own (it fires from console/
     * queue contexts too) — otherwise a log emitted while merely browsing
     * the monitor dashboard itself gets recorded as if it were the app's
     * own.
     */
    protected function shouldIgnore(): bool
    {
        return $this->monitor->isSelfRequest($this->config['ignore_paths'] ?? []);
    }
}
