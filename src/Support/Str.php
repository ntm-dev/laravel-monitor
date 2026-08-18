<?php

namespace LaravelMonitor\Support;

use Illuminate\Support\Str as IlluminateStr;

class Str extends IlluminateStr
{
    public static function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (static::is($pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}
