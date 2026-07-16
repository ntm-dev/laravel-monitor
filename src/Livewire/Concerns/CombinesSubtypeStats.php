<?php

namespace LaravelMonitor\Livewire\Concerns;

use Illuminate\Support\Collection;

trait CombinesSubtypeStats
{
    /**
     * Collapse a statsBySubtype() breakdown back into one overall stats()-
     * shaped object (count, avg_duration, max_duration, min_duration,
     * total_duration) — the same total a direct stats() call with no
     * subtype filter would return, derived from the single grouped query
     * instead of a second, separate scan.
     *
     * @param  Collection<string, object{count: int, avg_duration: ?float, max_duration: ?float, min_duration: ?float, total_duration: ?float}>  $bySubtype
     */
    protected function combineStats(Collection $bySubtype): object
    {
        if ($bySubtype->isEmpty()) {
            return (object) [
                'count' => 0,
                'avg_duration' => null,
                'max_duration' => null,
                'min_duration' => null,
                'total_duration' => null,
            ];
        }

        $count = $bySubtype->sum('count');
        $totalDuration = $bySubtype->sum('total_duration');

        return (object) [
            'count' => $count,
            'avg_duration' => $count > 0 ? round($totalDuration / $count, 2) : null,
            'max_duration' => $bySubtype->pluck('max_duration')->filter(fn ($v) => $v !== null)->max(),
            'min_duration' => $bySubtype->pluck('min_duration')->filter(fn ($v) => $v !== null)->min(),
            'total_duration' => round($totalDuration, 2),
        ];
    }
}
