<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonInterval;
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

    /** Manual issue-priority levels, value => human label. */
    public const PRIORITIES = [
        'none' => 'No priority',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    /**
     * Badge classes for a query's actual PDO connection role — keyed by
     * Illuminate\Database\Events\QueryExecuted::$readWriteType (null when
     * running under a Laravel version that doesn't report it, or when it's
     * ambiguous across a sampled group; see Recorders\Queries).
     */
    public const CONNECTION_TYPE_BADGES = [
        'write' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'read' => 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
        'direct' => 'border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 text-neutral-500 dark:text-neutral-400',
    ];

    /**
     * Memoized durationUnits() results, keyed by locale.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $durationUnits = [];

    /** Duration units, largest first, as [milliseconds-per-unit, suffix]. */
    protected const DURATION_UNITS = [
        [3_600_000, 'h'],
        [60_000, 'm'],
        [1_000, 's'],
        [1, 'ms'],
    ];

    /**
     * Render a millisecond duration in the largest whole unit it reaches:
     * "918ms", "1.73s". Walks the unit ladder (h, m, s, ms) largest-first
     * and uses the first one the value reaches 1 of; anything under 1ms
     * drops to μs so it doesn't round away to "0ms".
     */
    public static function duration(int|float|null $milliseconds, string $fallback = '—'): string
    {
        if ($milliseconds === null) {
            return $fallback;
        }

        foreach (self::DURATION_UNITS as [$unitMs, $suffix]) {
            if ($milliseconds >= $unitMs) {
                return rtrim(rtrim(number_format($milliseconds / $unitMs, 2), '0'), '.').$suffix;
            }
        }

        if ($milliseconds > 0) {
            return rtrim(rtrim(number_format($milliseconds * 1000, 2), '0'), '.').'μs';
        }

        return rtrim(rtrim(number_format($milliseconds, 2), '0'), '.').'ms';
    }

    /**
     * `:count`-templated suffix per duration unit, keyed by the letter a
     * caller groups by: `[':countd', ':counth', ...]` in English,
     * `[':count ngày', ':count giờ', ...]` in Vietnamese. Read out of Carbon
     * rather than hardcoded, so a locale the package itself doesn't ship
     * still gets its own units.
     *
     * Laravel never syncs Carbon's locale to the app's — nothing listens for
     * LocaleUpdated — so it is set per interval here rather than assumed.
     * The template is recovered by rendering a probe count and putting the
     * placeholder back, because only the rendered form has been through
     * Carbon's own plural selection.
     *
     * Keyed off the app locale, not Preferences::locale(), so these always
     * agree with the __() strings beside them; the middleware has already
     * pushed the preference onto the app. Memoized because a table renders
     * one countdown per row and Preferences::availableLocales() globs the
     * lang directory on every call.
     *
     * @return array<string, string>
     */
    public static function durationUnits(): array
    {
        $locale = app()->getLocale();

        if (isset(self::$durationUnits[$locale])) {
            return self::$durationUnits[$locale];
        }

        $probe = 2;

        $intervals = [
            'd' => CarbonInterval::days($probe),
            'h' => CarbonInterval::hours($probe),
            'm' => CarbonInterval::minutes($probe),
            's' => CarbonInterval::seconds($probe),
        ];

        $units = [];

        foreach ($intervals as $key => $interval) {
            $units[$key] = str_replace(
                (string) $probe,
                ':count',
                $interval->locale($locale)->forHumans(['short' => true]),
            );
        }

        return self::$durationUnits[$locale] = $units;
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
