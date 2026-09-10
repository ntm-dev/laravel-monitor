<?php

namespace LaravelMonitor\Support;

use Illuminate\Support\Collection;

class Sql
{
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
     * How many distinct normalized SQL shapes repeat among this
     * request/job/command's own `query` rows (N+1 signal) — three rows
     * sharing one shape count as one duplicate, not three.
     *
     * @param  Collection<int, object>  $queryRows
     */
    public static function duplicateCount(Collection $queryRows): int
    {
        return $queryRows
            ->groupBy('key')
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->count();
    }
}
