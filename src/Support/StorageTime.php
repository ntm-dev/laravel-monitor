<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonImmutable;

/**
 * Timestamp -> Carbon for comparisons against `created_at`, which is stored as
 * naive wall-clock time in config('app.timezone'). Bindings keep their own timezone
 * and Carbon 3's createFromTimestamp() defaults to UTC, so always convert here.
 */
class StorageTime
{
    /** The same instant, expressed in the timezone `created_at` is stored in. */
    public static function fromTimestamp(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone', 'UTC'));
    }
}
