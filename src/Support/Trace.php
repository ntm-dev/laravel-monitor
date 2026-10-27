<?php

namespace LaravelMonitor\Support;

use LaravelMonitor\LazyValue;

use function array_shift;
use function debug_backtrace;
use function implode;

/**
 * Call-stack text for the Queries/Jobs/OutgoingRequests "trace" detail option
 * (config/monitor.php recorders.*.details.trace, off by default outside local).
 */
final class Trace
{

    /**
     * getTraceAsString()-style text ("#0 file(line): Class->method()"), formatted
     * lazily when the entry is flushed. Null when there are no frames to show.
     *
     * @return LazyValue<string>|null
     */
    public static function capture(): ?LazyValue
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Frame 0 is the recorder's own capture() call.
        array_shift($frames);

        if ($frames === []) {
            return null;
        }

        return new LazyValue(static fn () => self::format($frames));
    }

    /**
     * Vendor frames with a class are stored as just "Class->method()", except
     * the call-site frame, which keeps its file:line for location().
     *
     * @param  list<array<string, mixed>>  $frames
     */
    private static function format(array $frames): string
    {
        $location = app(Location::class);
        [$callerFile, $callerLine] = $location->forQueryTrace([[], ...$frames]);
        $lines = [];

        foreach ($frames as $index => $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? null;
            $function = ($class ?? '').($frame['type'] ?? '').($frame['function'] ?? '{closure}');

            $isCaller = $file !== null && $callerFile !== null
                && $location->normalizeFile($file) === $callerFile && $line === $callerLine;

            if ($class !== null && $file !== null && ! $isCaller && $location->isVendorFile($file)) {
                $lines[] = "#{$index} {$function}()";

                continue;
            }

            $file = $file !== null ? $location->normalizeFile($file) : '[internal]';
            $lines[] = "#{$index} {$file}({$line}): {$function}()";
        }

        return implode("\n", $lines);
    }

    /**
     * "file:line" of the call site behind a stored trace — relative to the
     * project root, or absolute when $absolute is set. Null if none found.
     */
    public static function location(?string $trace, bool $absolute = false): ?string
    {
        if ($trace === null || $trace === '') {
            return null;
        }

        $location = app(Location::class);
        [$file, $line] = $location->forStoredTrace($trace);

        if ($file === null) {
            return null;
        }

        return ($absolute ? $location->absoluteFile($file) : $file).':'.($line ?? 0);
    }
}
