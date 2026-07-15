<?php

namespace LaravelMonitor\Support;

class Sql
{
    /**
     * Statement keywords that mutate data/schema — everything else (select,
     * show, explain, with, ...) is treated as a read.
     */
    protected const WRITE_KEYWORDS = [
        'insert', 'update', 'delete', 'replace',
        'alter', 'create', 'drop', 'truncate', 'rename',
    ];

    /** Whether the query's leading keyword is a write/mutating statement. */
    public static function isWrite(string $sql): bool
    {
        $first = strtolower((string) strtok(ltrim($sql), " \t\n\r("));

        return in_array($first, self::WRITE_KEYWORDS, true);
    }

    public static function type(string $sql): string
    {
        return self::isWrite($sql) ? 'write' : 'read';
    }
}
