<?php

namespace LaravelMonitor\Support;

/**
 * Exception grouping key: class + normalized message + top stack frame.
 * Kept in sync with database/demo/seed.php, which builds the same hash by hand.
 */
class Fingerprint
{
    public static function for(string $class, ?string $message, ?string $topFrame = null): string
    {
        return substr(sha1(implode('|', [
            $class,
            self::normalizeMessage((string) $message),
            (string) $topFrame,
        ])), 0, 32);
    }

    /** Replaces hex literals, hashes, numbers and quoted values with placeholders. */
    public static function normalizeMessage(string $message): string
    {
        $message = preg_replace('/0x[0-9a-fA-F]+/', '{hex}', $message) ?? $message;
        $message = preg_replace('/\b[0-9a-f]{16,}\b/i', '{hash}', $message) ?? $message;
        $message = preg_replace('/\d+/', '{n}', $message) ?? $message;
        $message = preg_replace('/[\'"][^\'"]*[\'"]/', '{s}', $message) ?? $message;

        return trim($message);
    }
}
