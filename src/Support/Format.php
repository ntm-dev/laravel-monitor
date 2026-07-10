<?php

namespace LaravelMonitor\Support;

use DateTimeInterface;

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

        return round($milliseconds).'ms';
    }

    public static function datetime(DateTimeInterface $date): string
    {
        return $date->format(self::DATETIME);
    }

    public static function timezone(): string
    {
        return strtoupper(config('app.timezone', 'UTC'));
    }
}
