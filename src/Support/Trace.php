<?php

namespace LaravelMonitor\Support;

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
    protected const DEPTH = 30;

    /**
     * PHP's own getTraceAsString() style ("#0 file(line): Class->method()"),
     * one frame per line, relative file paths. Null when there's nothing
     * past the recorder's own call site to show.
     */
    public static function capture(): ?string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::DEPTH + 1);

        // Frame 0 is always inside the recorder's own trace-building method
        // — never meaningful to show as the caller.
        array_shift($frames);

        if ($frames === []) {
            return null;
        }

        $location = app(Location::class);
        $lines = [];

        foreach ($frames as $index => $frame) {
            $file = isset($frame['file']) ? $location->normalizeFile($frame['file']) : '[internal]';
            $line = $frame['line'] ?? 0;
            $function = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '{closure}');

            $lines[] = "#{$index} {$file}({$line}): {$function}()";
        }

        return implode("\n", $lines);
    }
}
