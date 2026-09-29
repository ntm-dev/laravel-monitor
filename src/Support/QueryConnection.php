<?php

namespace LaravelMonitor\Support;

/**
 * Packs a query row's connection name and PDO role into one `subtype`
 * value ("mysql:read"), avoiding a JSON_EXTRACT of `payload` per row.
 */
class QueryConnection
{
    public static function pack(string $connection, ?string $connectionType): string
    {
        return $connectionType !== null ? "{$connection}:{$connectionType}" : $connection;
    }

    /**
     * @return array{0: ?string, 1: ?string} [connection, connection_type]
     */
    public static function parse(?string $subtype): array
    {
        if ($subtype === null || $subtype === '') {
            return [null, null];
        }

        return str_contains($subtype, ':') ? explode(':', $subtype, 2) : [$subtype, null];
    }
}
