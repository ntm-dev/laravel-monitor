<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Throwable;

class Logs extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, [$this, 'record']);
    }

    public function record(MessageLogged $event): void
    {
        // Exceptions have their own recorder.
        if (($event->context['exception'] ?? null) instanceof Throwable) {
            return;
        }

        $levels = $this->config['levels'] ?? ['emergency', 'alert', 'critical', 'error', 'warning'];

        if (! in_array($event->level, $levels, true)) {
            return;
        }

        $context = collect($event->context)
            ->reject(fn ($value) => is_object($value) || is_resource($value))
            ->all();

        $this->monitor->record(
            type: 'log',
            key: Str::limit((string) $event->message, 250),
            payload: [
                'message' => Str::limit((string) $event->message, 1000),
                'level' => $event->level,
                'context' => Str::limit(json_encode($context) ?: '{}', 1000),
            ],
            subtype: $event->level,
        );
    }
}
