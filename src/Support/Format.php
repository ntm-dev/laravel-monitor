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
     * Manual issue-priority levels, value => human label — mirrors
     * Nightwatch's five-level priority field on an Issue.
     */
    public const PRIORITIES = [
        'none' => 'No priority',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

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

    public static function priorityLabel(string $priority): string
    {
        return self::PRIORITIES[$priority] ?? self::PRIORITIES['none'];
    }

    /**
     * Badge colour classes for an HTTP status code: light tint matching its
     * severity (5xx rose, 4xx amber, otherwise green) — shared by every
     * status badge on the Request Detail page (header, General summary).
     */
    public static function statusBadgeClass(int $status): string
    {
        return match (true) {
            $status >= 500 => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
            $status >= 400 => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
            default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        };
    }
}
