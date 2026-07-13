<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use LaravelMonitor\Support\Fingerprint;
use Throwable;

class Exceptions extends Recorder
{
    /**
     * Log levels that mean the exception crashed the request / job rather than
     * being caught and logged deliberately. Everything below "error" is treated
     * as handled — the developer chose to downgrade it.
     */
    protected const UNHANDLED_LEVELS = ['error', 'critical', 'alert', 'emergency'];

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

        $class = get_class($exception);
        $message = Str::limit($exception->getMessage(), 500);
        $file = $this->relativePath($exception->getFile());
        $frames = $this->frames($exception);

        $this->monitor->record(
            type: 'exception',
            key: Fingerprint::for($class, $exception->getMessage(), $file.':'.$exception->getLine()),
            payload: [
                'class' => $class,
                'message' => $message,
                'file' => $file,
                'line' => $exception->getLine(),
                'handled' => ! in_array($event->level, self::UNHANDLED_LEVELS, true),
                'php_version' => PHP_VERSION,
                'laravel_version' => $this->laravelVersion(),
                'server' => gethostname() ?: null,
                'frames' => $frames,
                // Kept for backward compatibility with existing consumers.
                'trace' => array_map(
                    fn ($frame) => $frame['file'].':'.$frame['line'].' '.$frame['label'],
                    $frames,
                ),
            ],
            subtype: in_array($event->level, self::UNHANDLED_LEVELS, true) ? 'unhandled' : 'handled',
        );
    }

    /**
     * Structured, Ignition-style frames: the throw site followed by the call
     * stack, each tagged as application or vendor code.
     *
     * @return array<int, array{file: string, line: int, function: string|null, label: string, vendor: bool}>
     */
    protected function frames(Throwable $exception): array
    {
        $frames = [[
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'function' => null,
            'class' => null,
            'type' => null,
        ]];

        foreach ($exception->getTrace() as $frame) {
            $frames[] = $frame + ['file' => '[internal]', 'line' => 0];

            if (count($frames) >= 30) {
                break;
            }
        }

        return array_map(function ($frame) {
            $file = $this->relativePath($frame['file'] ?? '[internal]');
            $function = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');

            return [
                'file' => $file,
                'line' => (int) ($frame['line'] ?? 0),
                'function' => $function !== '' ? $function : null,
                'label' => $function !== '' ? $function : '{main}',
                'vendor' => $this->isVendor($file),
            ];
        }, $frames);
    }

    protected function isVendor(string $path): bool
    {
        return str_starts_with($path, 'vendor'.DIRECTORY_SEPARATOR)
            || str_starts_with($path, 'vendor/')
            || $path === '[internal]';
    }

    protected function laravelVersion(): ?string
    {
        try {
            return app()->version();
        } catch (Throwable) {
            return null;
        }
    }

    protected function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
