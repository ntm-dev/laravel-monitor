<?php

namespace LaravelMonitor\Support;

use function number_format;

/**
 * Numeric formatters shared by the dashboard views.
 *
 * Deliberately not Illuminate\Support\Number: that class only exists from
 * Laravel 10.39 (this package supports ^10.0) and its fileSize() goes through
 * Number::format(), which throws when ext-intl is missing on the host app.
 */
class Number
{
    /** Binary units above a plain byte, smallest first — one /1024 step each. */
    protected const UNITS = ['KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * Render a byte count in the largest unit it reaches: "918 B", "11.8 MB".
     * Raw bytes stay whole; every scaled unit keeps one decimal so a column of
     * values lines up. Returns $fallback (null by default, so array_filter()
     * can drop the row) when there is no value to show.
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
