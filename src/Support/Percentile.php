<?php

namespace LaravelMonitor\Support;

use function ceil;
use function count;
use function max;
use function min;
use function round;
use function sort;

/**
 * Nearest-rank percentile over a plain array of values — shared by
 * Storage\DatabaseStorage (SQL can't compute a percentile portably across
 * MySQL/Postgres/SQLite, so it's always derived from a sampled/fetched set
 * of raw values instead) and any other caller doing its own in-PHP
 * aggregation over rows it already pulled (see Livewire\Schedule, which
 * groups scheduled_task rows by cadence rather than by key alone).
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
