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

    /** Looked up once per process — gethostname() is a syscall. */
    public static function name(): ?string
    {
        if (! self::$resolved) {
            self::$name = gethostname() ?: null;
            self::$resolved = true;
        }

        return self::$name;
    }
}
