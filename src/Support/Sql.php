<?php

namespace LaravelMonitor\Support;

use Illuminate\Support\Collection;

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

    /**
     * How many of this request/job/command's own `slow_query` rows share a
     * normalized shape with at least one other — surfaced on the Queries
     * summary card as an N+1 signal. `key` is already the normalized shape
     * (see Recorders\Queries::record()), so no re-normalizing needed here.
     *
     * @param  Collection<int, object>  $queryRows
     */
    public static function duplicateCount(Collection $queryRows): int
    {
        return $queryRows
            ->groupBy('key')
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->flatten()
            ->count();
    }
}
