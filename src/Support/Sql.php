<?php

namespace LaravelMonitor\Support;

use Illuminate\Support\Collection;

class Sql
{
    /**
     * Grouping key for a query: collapses `IN (?, ?, ...)` lists and multi-row
     * `VALUES (?,?), (?,?), ...` so differing list/batch sizes group together.
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
     * Number of query shapes (`key`) that occur more than once (N+1 signal);
     * three rows sharing one shape count as one.
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
