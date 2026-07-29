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
     * Structured, Ignition-style frames — the call stack from the throw site
     * outward, each tagged as application or vendor code.
     *
     * @return array<int, array{file: string, line: int, function: string|null, label: string, vendor: bool}>
     */
    protected function frames(Throwable $exception): array
    {
        $trace = $exception->getTrace();

        // Mirrors Laravel's own Foundation\Exceptions\Renderer\Exception::
        // frames(): trace[0]'s own file/line is already the throw site (it's
        // literally where $exception->getFile()/getLine() come from), so
        // prepending a second frame built from those same two values — as
        // this method used to — just duplicated it under a meaningless
        // "{main}" label. The only real gap is that trace[0] sometimes has
        // no class/function of its own; when that happens, borrow it from
        // trace[1] instead of showing a blank frame.
        if (count($trace) > 1 && empty($trace[0]['class'] ?? null) && empty($trace[0]['function'] ?? null)) {
            $trace[0]['class'] = $trace[1]['class'] ?? null;
            $trace[0]['type'] = $trace[1]['type'] ?? null;
            $trace[0]['function'] = $trace[1]['function'] ?? null;
            $trace[0]['args'] = $trace[1]['args'] ?? [];
        }

        $frames = array_map(fn ($frame) => $frame + ['file' => '[internal]', 'line' => 0], array_slice($trace, 0, 30));

        return array_map(function ($frame) {
            $file = $this->relativePath($frame['file'] ?? '[internal]');
            $function = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');

            return [
                'file' => $file,
                'line' => (int) ($frame['line'] ?? 0),
                'function' => $function !== '' ? $function : null,
                'label' => $function !== '' ? $function : '{main}',
                'vendor' => $this->isVendor($file),
                // Argument *types* only (mirrors Laravel's own renderer,
                // Foundation\Exceptions\Renderer\Frame::args() /
                // Symfony's FlattenException::flattenArgs()) — never the
                // actual values, so a request/model/password passed into a
                // throwing call never ends up sitting in monitor_entries.
                'args' => $this->argTypes($frame['args'] ?? []),
            ];
        }, $frames);
    }

    /**
     * @param  array<mixed>  $args
     * @return list<string>
     */
    protected function argTypes(array $args): array
    {
        return array_values(array_map(fn ($value) => match (true) {
            is_object($value) => 'object('.get_class($value).')',
            is_array($value) => 'array',
            is_null($value) => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_resource($value) => 'resource',
            default => 'string',
        }, $args));
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
