<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Types\Str;
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

        $message = (string) $event->message;

        // No request/user object on MessageLogged itself (it can fire from
        // console/queue contexts too) — resolve the currently authenticated
        // user the same way Recorders\Requests does, guarded the same way
        // since auth() can throw when no guard/session is bound outside an
        // HTTP request.
        try {
            $userId = auth()->user()?->getAuthIdentifier();
        } catch (Throwable) {
            $userId = null;
        }

        $this->monitor->record(
            type: RecordType::Log,
            key: Str::tinyText($message),
            payload: [
                'message' => Str::text($message),
                'context' => Str::mediumText(json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '{}'),
            ],
            subtype: $event->level,
            userId: $userId,
        );
    }
}
