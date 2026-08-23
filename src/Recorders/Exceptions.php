<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Str;
use LaravelMonitor\Support\Fingerprint;
use LaravelMonitor\Support\RecordType;
use Throwable;

use function array_map;
use function array_slice;
use function array_unshift;
use function array_values;
use function count;
use function debug_backtrace;
use function get_class;
use function gethostname;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_null;
use function is_object;
use function is_resource;
use function str_replace;
use function str_starts_with;

class Exceptions extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $hook = function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->reportable($this->record(...));
            }
        };

        // The exception handler is normally resolved lazily, the first time
        // something actually throws — long after this recorder registers —
        // so afterResolving() is what catches it. But recorders can register
        // after that first resolution too (e.g. a previous exception already
        // forced it during this same process), so also fire immediately if
        // it's already sitting in the container.
        $this->monitor->app->afterResolving(ExceptionHandler::class, $hook);

        if ($this->monitor->app->resolved(ExceptionHandler::class)) {
            $hook($this->monitor->app->make(ExceptionHandler::class));
        }
    }

    public function record(Throwable $exception): void
    {
        $class = get_class($exception);
        $message = Str::limit($exception->getMessage(), 500);
        [$file, $line] = $this->monitor->location->forException($exception);
        $frames = $this->frames($exception);
        $handled = $this->wasReportedDeliberately();

        $this->monitor->record(
            type: RecordType::Exception,
            key: Fingerprint::for($class, $exception->getMessage(), "{$file}:{$line}"),
            payload: [
                'class' => $class,
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'handled' => $handled,
                'php_version' => PHP_VERSION,
                'laravel_version' => $this->monitor->laravelVersion(),
                'server' => gethostname() ?: null,
                'frames' => $frames,
                // Kept for backward compatibility with existing consumers.
                'trace' => array_map(
                    fn ($frame) => $frame['file'].':'.$frame['line'].' '.$frame['label'],
                    $frames,
                ),
            ],
            subtype: $handled ? 'handled' : 'unhandled',
        );
    }

    /**
     * Distinguishes a deliberate `report($e)` call inside application code
     * (caught, logged, request/job carries on) from the exception handler's
     * own automatic path for something that crashed the request/job outright
     * (an uncaught throw, a failed queue job, ...) — both end up calling the
     * handler's report() method, but only the former passes through the
     * global report() *function* first, which shows up as its own stack
     * frame with no class/`->`/`::` attached to it.
     */
    protected function wasReportedDeliberately(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            if (($frame['function'] ?? null) === 'report' && ! isset($frame['class'])) {
                return true;
            }
        }

        return false;
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

        // getTrace() never includes the throw site itself as its own entry —
        // PHP only records *call* sites, and a `throw` isn't a call. trace[0]'s
        // file/line is actually where trace[0]'s own function was *called
        // from* (one level further out), not where it threw. So the throw
        // site has to be prepended separately from $exception->getFile()/
        // getLine(), same as Laravel's own Foundation\Exceptions\Renderer\
        // Exception::frames() does via Symfony's FlattenException::setTrace().
        // That synthetic frame starts with no class/function of its own;
        // backfill it from the original trace[0], since that's the function
        // that was actually executing when the exception was thrown.
        array_unshift($trace, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'function' => null,
            'class' => null,
            'type' => null,
        ]);

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

    protected function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
