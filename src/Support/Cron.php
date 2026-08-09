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
 * Turns a scheduled task's raw cron expression back into the phrase it was
 * declared with ("Every 5 minutes", "Daily at 13:30") and into the date it is
 * next due.
 *
 * Recorders\ScheduledTasks stores `expression` / `timezone` / `repeat_seconds`
 * on every entry's payload, so the schedule list describes a task from its own
 * recorded runs rather than from the app's Schedule instance — on Laravel 10
 * only the console kernel populates that instance, leaving it empty during the
 * dashboard's own web request.
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
     * When the expression is next due, in UTC, or null when it can't be
     * parsed. dragonmantank/cron-expression ships with illuminate/console, so
     * it is only missing from an install that never runs a scheduler.
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

            // A sub-minute task fires every $repeatSeconds *within* each minute
            // its expression matches, so while that minute is the current one
            // the cron date alone points a whole minute past the real next run.
            // Same correction Illuminate's ScheduleListCommand applies.
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
     * The phrase for one of the cadences Illuminate's ManagesFrequencies can
     * produce, or null for an expression it never emits (a hand-written
     * `30 4 1,15 * 5`, say) — those are better shown raw than guessed at.
     *
     * Ordered most specific first, since match() takes the first arm that
     * holds: "0 * * * *" is plain "Hourly", not "Hourly at minute 0".
     *
     * The matcher returns its captures rather than filling a by-reference
     * out-param — `$matches` is local to the closure, which is what lets this
     * stay an arrow function. Each arm binds them to `$found` in its own
     * condition, and casts, because match() compares against true strictly.
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

    /**
     * The expression with its whitespace collapsed to single spaces, or null
     * when it isn't a five-field cron string. Illuminate never produces
     * anything else, but a payload recorded by a hand-rolled Event might.
     */
    protected static function normalize(?string $expression): ?string
    {
        $fields = preg_split('/\s+/', trim((string) $expression), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($fields) === 5 ? implode(' ', $fields) : null;
    }

    /**
     * "Every minute" / "Every 5 minutes" — two separate keys rather than one
     * pluralized string, because the singular drops the number entirely and
     * Illuminate's MessageSelector treats Vietnamese as a single-form locale
     * (every count would resolve to the "Every minute" half).
     */
    protected static function cadence(string $unit, int $count): string
    {
        return $count === 1
            ? self::line("every_{$unit}")
            : self::line("every_{$unit}s", ['count' => $count]);
    }

    /**
     * The locale's own name for a cron weekday number — 0 and 7 are both
     * Sunday. Carbon's translations rather than the package's own, so a
     * locale this package doesn't ship still names its days; its locale is
     * set explicitly for the reason given in Format::durationUnits().
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
