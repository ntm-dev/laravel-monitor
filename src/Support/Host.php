<?php

namespace LaravelMonitor\Support;

use function gethostname;

/**
 * @internal
 */
final class Host
{
    private static ?string $name = null;

    private static bool $resolved = false;

    /**
     * gethostname() is a syscall, and the name can't change mid-process, so
     * it's looked up once.
     */
    public static function name(): ?string
    {
        if (! self::$resolved) {
            self::$name = gethostname() ?: null;
            self::$resolved = true;
        }

        return self::$name;
    }
}
