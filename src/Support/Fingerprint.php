<?php

namespace LaravelMonitor\Support;

/**
 * Stable grouping key for exceptions.
 *
 * Occurrences collapse into one group when they share an exception class,
 * a normalized message (numbers / quoted ids stripped so "model 41" and
 * "model 92" group together) and the same top stack-trace frame. Kept in
 * sync with database/demo/seed.php, which builds the same hash by hand.
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

    /**
     * Strip volatile bits (ids, numbers, quoted values, hashes) so messages
     * that differ only by runtime data map to the same fingerprint.
     */
    public static function normalizeMessage(string $message): string
    {
        $message = preg_replace('/0x[0-9a-fA-F]+/', '{hex}', $message) ?? $message;
        $message = preg_replace('/\b[0-9a-f]{16,}\b/i', '{hash}', $message) ?? $message;
        $message = preg_replace('/\d+/', '{n}', $message) ?? $message;
        $message = preg_replace('/[\'"][^\'"]*[\'"]/', '{s}', $message) ?? $message;

        return trim($message);
    }
}
