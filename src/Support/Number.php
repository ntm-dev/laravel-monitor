<?php

namespace LaravelMonitor\Support;

use function number_format;

/**
 * Numeric formatters for the dashboard views. Not Illuminate\Support\Number:
 * it's missing from early Laravel 10 releases and its fileSize() requires ext-intl.
 */
class Number
{
    /** Binary units above a plain byte, smallest first — one /1024 step each. */
    protected const UNITS = ['KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * A byte count in the largest unit it reaches: "918 B", "11.8 MB".
     * Scaled units keep one decimal; null bytes return $fallback.
     */
    public static function fileSize(int|float|null $bytes, ?string $fallback = null): ?string
    {
        if ($bytes === null) {
            return $fallback;
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $scaled = $bytes;
        $unit = 'B';

        foreach (self::UNITS as $unit) {
            $scaled /= 1024;

            if ($scaled < 1024) {
                break;
            }
        }

        return number_format($scaled, 1).' '.$unit;
    }
}
