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

    /**
     * Collapses placeholder-count variance out of a query's shape before
     * it's used as a grouping key — an `IN (?, ?, ?)` filtering 3 ids and
     * the same query filtering 30 otherwise look like unrelated queries
     * and split the Queries page into one row per distinct list length.
     * Same idea for a multi-row `INSERT ... VALUES (?,?), (?,?), ...`
     * bulk insert: every batch size collapses to one representative row.
     */
    public static function normalizeKey(string $sql): string
    {
        $sql = preg_replace('/\bin\b\s*\(\s*\?(?:\s*,\s*\?)+\s*\)/i', 'IN (?)', $sql) ?? $sql;

        $sql = preg_replace_callback(
            '/\bvalues\b(\s*\(\s*\?(?:\s*,\s*\?)*\s*\))(?:\s*,\s*\(\s*\?(?:\s*,\s*\?)*\s*\))+/i',
            fn (array $matches) => 'VALUES'.$matches[1],
            $sql,
        ) ?? $sql;

        return $sql;
    }
}
