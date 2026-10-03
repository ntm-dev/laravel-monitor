<?php

namespace LaravelMonitor\Support;

use LaravelMonitor\LazyValue;

use function array_shift;
use function debug_backtrace;
use function implode;

/**
 * Full call-stack text for the "trace" detail options (Queries/Jobs/
 * OutgoingRequests — see config/monitor.php's recorders.*.details) — off by
 * default outside local, since capturing and formatting a deep backtrace on
 * every query/dispatch/outgoing call is real overhead most installs don't
 * want paying for unconditionally.
 */
final class Trace
{

    /**
     * PHP's own getTraceAsString() style ("#0 file(line): Class->method()"),
     * one frame per line, relative file paths. Null when there's nothing
     * past the recorder's own call site to show.
     *
     * Only the raw frames are captured here; formatting and shrinking run
     * when the entry is flushed (see Entry::toArray()), off the hot path.
     *
     * @return LazyValue<string>|null
     */
    public static function capture(): ?LazyValue
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Frame 0 is always inside the recorder's own trace-building method
        // — never meaningful to show as the caller.
        array_shift($frames);

        if ($frames === []) {
            return null;
        }

        return new LazyValue(static fn () => self::format($frames));
    }

    /**
     * Vendor frames that name a class are stored as just "Class->method()":
     * the dashboard shows exactly that for them, and their file:line is only
     * needed for the one frame the call site is read back from.
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
