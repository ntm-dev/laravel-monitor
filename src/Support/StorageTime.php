<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonImmutable;

/**
 * Timestamp -> Carbon conversions for values that end up compared against
 * `created_at`.
 *
 * Storage writes `created_at` as wall-clock naive to config('app.timezone')
 * (see DatabaseEntryWriter::store()/BuildsQueries::hydrate()), and
 * Illuminate\Database\Connection::prepareBindings() formats a bound
 * DateTimeInterface as-is, in whatever timezone it carries. Carbon 3's
 * createFromTimestamp() returns UTC rather than the default timezone Carbon 2
 * used, so calling it directly binds a UTC wall-clock string against
 * app-timezone rows — silently shifting every window by the app's UTC offset
 * (a "last 1 hour" view reaching back 10 hours on an Asia/Tokyo app).
 */
class StorageTime
{
    /** The same instant, expressed in the timezone `created_at` is stored in. */
    public static function fromTimestamp(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone', 'UTC'));
    }
}
