<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Throwable;

use function class_exists;
use function count;
use function implode;
use function preg_match;
use function preg_split;
use function str_pad;
use function trim;

/**
 * Turns a recorded cron expression back into the phrase it was declared with
 * ("Every 5 minutes", "Daily at 13:30") and the date it is next due.
 */
class Cron
{
    /** A Sunday — the zero point of cron's weekday numbering. */
    protected const WEEK_START = '1970-01-04';

    /**
     * Human phrase for a task's cadence, or null when it has none recorded.
     */
    public static function describe(?string $expression, int|float|null $repeatSeconds = null): ?string
    {
        if ($repeatSeconds !== null && $repeatSeconds > 0) {
            return self::cadence('second', (int) $repeatSeconds);
        }

        $normalized = self::normalize($expression);

        if ($normalized === null) {
            return trim((string) $expression) ?: null;
        }

        return self::phrase($normalized) ?? $normalized;
    }

    /**
     * Next due date in UTC; null when unparseable or dragonmantank/cron-expression
     * (shipped with illuminate/console) isn't installed.
     */
    public static function nextRunAt(
        ?string $expression,
        ?string $timezone = null,
        int|float|null $repeatSeconds = null,
    ): ?CarbonImmutable {
        $normalized = self::normalize($expression);

        if ($normalized === null || ! class_exists(CronExpression::class)) {
            return null;
        }

        try {
            $cron = new CronExpression($normalized);
            $now = CarbonImmutable::now($timezone !== null && $timezone !== '' ? $timezone : null);
            $next = CarbonImmutable::instance($cron->getNextRunDate($now));

            // Sub-minute task inside a matching minute: next run is the next
            // $repeatSeconds tick, not the next cron minute (as in ScheduleListCommand).
            if ($repeatSeconds !== null && $repeatSeconds > 0
                && $now->startOfMinute()->eq($cron->getPreviousRunDate($now, 0, true))) {
                $next = $now->endOfSecond()->ceilSeconds((int) $repeatSeconds);
            }
        } catch (Throwable) {
            return null;
        }

        return $next->utc();
    }

    /**
     * Phrase for a cadence ManagesFrequencies can produce, or null for anything
     * else (shown raw). Arms run most specific first; `(bool)` because match(true) is strict.
     */
    protected static function phrase(string $expression): ?string
    {
        $match = static fn (string $pattern): ?array => preg_match("#^{$pattern}$#", $expression, $matches)
            ? $matches
            : null;

        return match (true) {
            (bool) ($found = $match('\* \* \* \* \*')) => self::cadence('minute', 1),
            (bool) ($found = $match('\*/(\d+) \* \* \* \*')) => self::cadence('minute', (int) $found[1]),
            (bool) ($found = $match('0,30 \* \* \* \*')) => self::cadence('minute', 30),
            (bool) ($found = $match('0 \* \* \* \*')) => self::line('hourly'),
            (bool) ($found = $match('(\d+) \* \* \* \*')) => self::line('hourly_at', [
                'minute' => self::pad($found[1]),
            ]),
            (bool) ($found = $match('(\d+) 1-23/2 \* \* \*')) => self::line('every_odd_hour'),
            (bool) ($found = $match('(\d+) \*/(\d+) \* \* \*')) => self::cadence('hour', (int) $found[2]),
            (bool) ($found = $match('(\d+) (\d+) \* \* \*')) => self::line('daily_at', [
                'time' => self::time($found[2], $found[1]),
            ]),
            (bool) ($found = $match('(\d+) (\d+),(\d+) \* \* \*')) => self::line('twice_daily', [
                'first' => self::time($found[2], $found[1]),
                'second' => self::time($found[3], $found[1]),
            ]),
            (bool) ($found = $match('(\d+) (\d+) \* \* ([0-7])')) => self::line('weekly_on', [
                'day' => self::weekday((int) $found[3]),
                'time' => self::time($found[2], $found[1]),
            ]),
            (bool) ($found = $match('(\d+) (\d+) L \* \*')) => self::line('last_day_of_month', [
                'time' => self::time($found[2], $found[1]),
            ]),
            (bool) ($found = $match('(\d+) (\d+) (\d+) \* \*')) => self::line('monthly_on', [
                'day' => $found[3],
                'time' => self::time($found[2], $found[1]),
            ]),
            (bool) ($found = $match('(\d+) (\d+) (\d+),(\d+) \* \*')) => self::line('twice_monthly', [
                'first' => $found[3],
                'second' => $found[4],
                'time' => self::time($found[2], $found[1]),
            ]),
            (bool) ($found = $match('(\d+) (\d+) (\d+) 1-12/3 \*')) => self::line('quarterly', [
                'day' => $found[3],
                'time' => self::time($found[2], $found[1]),
            ]),
            (bool) ($found = $match('(\d+) (\d+) (\d+) (\d+) \*')) => self::line('yearly', [
                'time' => self::time($found[2], $found[1]),
            ]),
            default => null,
        };
    }

    /** Whitespace collapsed to single spaces; null unless it has exactly five fields. */
    protected static function normalize(?string $expression): ?string
    {
        $fields = preg_split('/\s+/', trim((string) $expression), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($fields) === 5 ? implode(' ', $fields) : null;
    }

    /**
     * Two keys, not one pluralized string: the singular has no number, and
     * MessageSelector treats Vietnamese as single-form.
     */
    protected static function cadence(string $unit, int $count): string
    {
        return $count === 1
            ? self::line("every_{$unit}")
            : self::line("every_{$unit}s", ['count' => $count]);
    }

    /**
     * Localized day name for a cron weekday (0 and 7 are Sunday), from Carbon's
     * translations; Carbon's locale is set explicitly as it isn't synced to the app's.
     */
    protected static function weekday(int $number): string
    {
        return CarbonImmutable::parse(self::WEEK_START)
            ->addDays($number % 7)
            ->locale(app()->getLocale())
            ->dayName;
    }

    protected static function time(string $hour, string $minute): string
    {
        return self::pad($hour).':'.self::pad($minute);
    }

    protected static function pad(string $value): string
    {
        return str_pad($value, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected static function line(string $key, array $replace = []): string
    {
        return __("monitor::messages.schedule.frequency.{$key}", $replace);
    }
}
