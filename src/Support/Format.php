<?php

namespace LaravelMonitor\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class Format
{
    /**
     * Timestamp format used across charts and detail tables.
     */
    public const DATETIME = 'M j, Y, H:i:s';

    /**
     * Minute-precision format used by the custom range picker
     * (matches <input type="datetime-local">).
     */
    public const RANGE = 'Y-m-d\TH:i';

    /**
     * Render a millisecond duration the way Nightwatch does: "918ms", "1.73s".
     */
    public static function duration(int|float|null $milliseconds, string $fallback = '—'): string
    {
        if ($milliseconds === null) {
            return $fallback;
        }

        if ($milliseconds >= 1000) {
            return rtrim(rtrim(number_format($milliseconds / 1000, 2), '0'), '.').'s';
        }

        return rtrim(rtrim(number_format($milliseconds, 2), '0'), '.').'ms';
    }

    public static function datetime(DateTimeInterface $date): string
    {
        return Carbon::instance($date)->setTimezone(Preferences::timezone())->format(self::DATETIME);
    }

    public static function timezone(): string
    {
        return strtoupper(Preferences::timezone());
    }
}
