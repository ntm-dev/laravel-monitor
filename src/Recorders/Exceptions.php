<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Throwable;

class Exceptions extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, [$this, 'record']);
    }

    public function record(MessageLogged $event): void
    {
        $exception = $event->context['exception'] ?? null;

        if (! $exception instanceof Throwable) {
            return;
        }

        $this->monitor->record(
            type: 'exception',
            key: get_class($exception),
            payload: [
                'class' => get_class($exception),
                'message' => Str::limit($exception->getMessage(), 500),
                'file' => $this->relativePath($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())
                    ->take(15)
                    ->map(fn ($frame) => ($this->relativePath($frame['file'] ?? '[internal]'))
                        .':'.($frame['line'] ?? 0)
                        .' '.($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''))
                    ->all(),
            ],
        );
    }

    protected function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
