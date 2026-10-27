<?php

namespace LaravelMonitor\Support;

use function ceil;
use function count;
use function max;
use function min;
use function round;
use function sort;

/**
 * Nearest-rank percentile over fetched values, computed in PHP because SQL
 * has no portable percentile across MySQL/Postgres/SQLite.
 */
class Percentile
{
    /**
     * @param  float[]  $values
     */
    public static function of(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $index = (int) ceil($percentile * count($values)) - 1;

        return round((float) $values[max(0, min($index, count($values) - 1))], 2);
    }
}
