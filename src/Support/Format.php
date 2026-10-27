<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class Format
{
    /** Default timestamp format, for a locale with no DATETIME_LOCALES entry. */
    public const DATETIME = 'M j, Y, H:i:s';

    /**
     * Locale => timestamp format, for locales whose date order differs from
     * DATETIME (translatedFormat() only translates names, not order).
     *
     * @var array<string, string>
     */
    public const DATETIME_LOCALES = [
        'vi' => 'd-m-Y H:i:s',
    ];

    /** Microsecond-precision format, matching `created_at`'s timestamp(6) column. */
    public const DATETIME_PRECISE = 'Y-m-d H:i:s.u';

    /** Custom range picker format (matches <input type="datetime-local">). */
    public const RANGE = 'Y-m-d\TH:i';

    /**
     * Issue priority levels, value => fallback English label. Only the keys are
     * used (validated by IssueManagePanel and DatabaseIssueStorage::setIssuePriority());
     * displayed text goes through priorityLabel().
     */
    public const PRIORITIES = [
        'none' => 'No priority',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    /** Text color for an HTTP method badge, keyed by the verb. */
    public const HTTP_METHOD_CLASSES = [
        'POST' => 'text-emerald-600',
        'PUT' => 'text-blue-500',
        'PATCH' => 'text-blue-500',
        'DELETE' => 'text-rose-600',
    ];

    /** Badge classes for a query's PDO connection role (QueryExecuted::$readWriteType). */
    public const CONNECTION_TYPE_BADGES = [
        'write' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'read' => 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
        'direct' => 'border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 text-neutral-500 dark:text-neutral-400',
    ];

    /**
     * durationUnits() results, keyed by locale.
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
     * A millisecond duration in the largest unit it reaches: "918ms", "1.73s".
     * Under 1ms drops to μs so it doesn't round to "0ms".
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
     * Text color for an HTTP method badge. "ANY" and "GET/POST"-style values
     * (the merged Unmatched Route row, see Livewire\Requests::presentRoute()) get their own color.
     */
    public static function httpMethodClass(?string $method): string
    {
        if ($method === 'ANY' || ($method !== null && str_contains($method, '/'))) {
            return 'text-violet-600 dark:text-violet-400';
        }

        return self::HTTP_METHOD_CLASSES[$method] ?? 'text-neutral-500 dark:text-neutral-400';
    }

    /**
     * `:count`-templated short suffix per unit (d/h/m/s), e.g. ':countd', read
     * from Carbon by rendering a probe count. Carbon's locale isn't synced to
     * the app's, so it's set per interval. Memoized per app locale.
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

    public static function datetime(DateTimeInterface $date, ?string $format = null): string
    {
        $locale = app()->getLocale();

        // translatedFormat() with an explicit locale: plain format() renders
        // month/day names in English.
        return Carbon::instance($date)
            ->setTimezone(Preferences::timezone())
            ->locale($locale)
            ->translatedFormat(blank($format) ? (self::DATETIME_LOCALES[$locale] ?? self::DATETIME) : $format);
    }

    /**
     * "12.5%" — one decimal, dropped when it's zero ("12%").
     */
    public static function percent(int|float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%';
    }

    /**
     * Same locale-aware layout as datetime(), with milliseconds after the
     * seconds ("H:i:s" becomes "H:i:s.v").
     */
    public static function datetimeMs(DateTimeInterface $date): string
    {
        $format = self::DATETIME_LOCALES[app()->getLocale()] ?? self::DATETIME;

        return self::datetime($date, str_replace('H:i:s', 'H:i:s.v', $format));
    }

    /**
     * Same layout as datetimeMs(), with microseconds ("H:i:s.u").
     */
    public static function datetimeUs(DateTimeInterface $date): string
    {
        $format = self::DATETIME_LOCALES[app()->getLocale()] ?? self::DATETIME;

        return self::datetime($date, str_replace('H:i:s', 'H:i:s.u', $format));
    }

    /**
     * When an entry's run began: payload `started_at`, else created_at (the
     * run's end) minus the duration. Carbon twin of MergesJobTimelines::startedAt().
     */
    public static function startedAt(object $entry): ?Carbon
    {
        $startedAt = $entry->payload['started_at'] ?? null;

        if ($startedAt !== null) {
            return Carbon::createFromTimestamp((float) $startedAt);
        }

        if ($entry->created_at === null) {
            return null;
        }

        return Carbon::instance($entry->created_at)->subMilliseconds((int) round((float) ($entry->duration ?? 0)));
    }

    /** Timestamp suffix for the viewer's timezone: "UTC", or an offset like "+07:00". */
    public static function timezone(): string
    {
        $tz = Preferences::timezone();

        return $tz === 'UTC' ? 'UTC' : Carbon::now($tz)->format('P');
    }

    public static function priorityLabel(string $priority): string
    {
        $key = array_key_exists($priority, self::PRIORITIES) ? $priority : 'none';

        return __('monitor::messages.issue.priority_'.$key);
    }

    /**
     * Every priority level as value => translated label.
     *
     * @return array<string, string>
     */
    public static function priorityOptions(): array
    {
        return collect(array_keys(self::PRIORITIES))
            ->mapWithKeys(fn ($key) => [$key => self::priorityLabel($key)])
            ->all();
    }

    /** Badge classes for an HTTP status code: 5xx rose, 4xx amber, otherwise green. */
    public static function statusBadgeClass(int $status): string
    {
        return match (true) {
            $status >= 500 => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
            $status >= 400 => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
            default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        };
    }

    /** The reason phrase for an HTTP status code (e.g. 404 -> "Not Found"), null for a code Symfony doesn't recognize. */
    public static function statusText(int $status): ?string
    {
        return Response::$statusTexts[$status] ?? null;
    }
}
