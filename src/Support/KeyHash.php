<?php

namespace LaravelMonitor\Support;

/**
 * Stable, opaque path-segment id for a grouping key (route, class name, SQL shape, ...).
 * One-way; Contracts\HashResolver reverses it by matching against known keys.
 */
class KeyHash
{
    public static function for(string $key): string
    {
        return substr(sha1($key), 0, 32);
    }
}
