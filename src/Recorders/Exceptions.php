<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;
use LaravelMonitor\LazyValue;
use LaravelMonitor\Support\Fingerprint;
use LaravelMonitor\Support\Host;
use LaravelMonitor\Support\RecordType;
use Throwable;
use WeakMap;

use function array_map;
use function array_slice;
use function array_unshift;
use function array_values;
use function count;
use function debug_backtrace;
use function get_class;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_null;
use function is_object;
use function is_resource;
use function method_exists;
use function spl_object_id;
use function str_replace;
use function str_starts_with;

class Exceptions extends Recorder
{
    /** @var WeakMap<Throwable, true>|null */
    protected ?WeakMap $recorded = null;

    /**
     * Exceptions the request/job lifecycle events carried, waiting to be
     * checked against $recorded at flush.
     *
     * @var array<int, Throwable>
     */
    protected array $candidates = [];

    public function register(Dispatcher $events): void
    {
        // Fallback for a handler whose report() skips the reportable()
        // callbacks below: whatever it never recorded is picked up at flush.
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $this->consider($event->exception));
        $events->listen(JobFailed::class, fn (JobFailed $event) => $this->consider($event->exception));
        $events->listen(RequestHandled::class, fn (RequestHandled $event) => $this->consider($event->response->exception ?? null));
        $this->monitor->flushing($this->recordUnreported(...));

        $hook = function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->reportable($this->record(...));
            }
        };

        // The handler is usually resolved lazily on the first throw, but may
        // already be resolved by the time this recorder registers.
        $this->monitor->app->afterResolving(ExceptionHandler::class, $hook);

        if ($this->monitor->app->resolved(ExceptionHandler::class)) {
            $hook($this->monitor->app->make(ExceptionHandler::class));
        }
    }

    protected function consider(mixed $exception): void
    {
        if ($exception instanceof Throwable) {
            $this->candidates[spl_object_id($exception)] = $exception;
        }
    }

    protected function recordUnreported(): void
    {
        $candidates = $this->candidates;
        $this->candidates = [];

        foreach ($candidates as $exception) {
            if (! isset($this->recorded[$exception]) && $this->shouldReport($exception)) {
                $this->record($exception);
            }
        }
    }

    /** Honors the handler's own do-not-report rules (validation, 404, ...). */
    protected function shouldReport(Throwable $exception): bool
    {
        $handler = $this->monitor->app->make(ExceptionHandler::class);

        return ! method_exists($handler, 'shouldReport') || $handler->shouldReport($exception);
    }

    public function record(Throwable $exception): void
    {
        $this->recorded ??= new WeakMap();

        if (isset($this->recorded[$exception])) {
            return;
        }

        $this->recorded[$exception] = true;

        $class = get_class($exception);
        $message = Str::limit($exception->getMessage(), 500);
        $handled = $this->wasReportedDeliberately();

        // Location, frames and fingerprint are resolved once, at storage time;
        // only the backtrace check above must run at the throw.
        $resolved = null;
        $details = function () use ($exception, &$resolved): array {
            if ($resolved === null) {
                [$file, $line] = $this->monitor->location->forException($exception);
                $resolved = ['file' => $file, 'line' => $line, 'frames' => $this->frames($exception)];
            }

            return $resolved;
        };

        $this->monitor->record(
            type: RecordType::Exception,
            key: new LazyValue(function () use ($details, $class, $exception): string {
                $details = $details();

                return Fingerprint::for($class, $exception->getMessage(), "{$details['file']}:{$details['line']}");
            }),
            payload: [
                'class' => $class,
                'message' => $message,
                'file' => new LazyValue(fn () => $details()['file']),
                'line' => new LazyValue(fn () => $details()['line']),
                'handled' => $handled,
                'php_version' => PHP_VERSION,
                'laravel_version' => $this->monitor->laravelVersion(),
                'server' => Host::name(),
                'frames' => new LazyValue(fn () => $details()['frames']),
                // Kept for backward compatibility with existing consumers.
                'trace' => new LazyValue(fn () => array_map(
                    static fn ($frame) => $frame['file'].':'.$frame['line'].' '.$frame['label'],
                    $details()['frames'],
                )),
            ],
            subtype: $handled ? 'handled' : 'unhandled',
            userId: $this->monitor->lazyCurrentUserId(),
        );

        // Unhandled exceptions skip the events that advance the lifecycle stage.
        if (! $handled) {
            $this->monitor->markUnhandledException();
        }
    }

    /**
     * True when app code called the global report() helper (a caught,
     * handled exception), detected by its class-less stack frame.
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

        // getTrace() omits the throw site itself, so prepend it.
        array_unshift($trace, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'function' => null,
            'class' => null,
            'type' => null,
        ]);

        // Each getTrace() entry names the function *called* at its file/line;
        // shift names up one frame so each is labelled with the function
        // executing at that location.
        for ($i = 0, $last = count($trace) - 1; $i < $last; $i++) {
            $trace[$i]['class'] = $trace[$i + 1]['class'] ?? null;
            $trace[$i]['type'] = $trace[$i + 1]['type'] ?? null;
            $trace[$i]['function'] = $trace[$i + 1]['function'] ?? null;
            $trace[$i]['args'] = $trace[$i + 1]['args'] ?? [];
        }

        $frames = array_map(fn ($frame) => $frame + ['file' => '[internal]', 'line' => 0], $trace);

        return array_map(function ($frame) {
            $file = $this->relativePath($frame['file'] ?? '[internal]');
            $function = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');

            return [
                'file' => $file,
                'line' => (int) ($frame['line'] ?? 0),
                'function' => $function !== '' ? $function : null,
                'label' => $function !== '' ? $function : '{main}',
                'vendor' => $this->isVendor($file),
                // Types only, never values, so secrets never get stored.
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
