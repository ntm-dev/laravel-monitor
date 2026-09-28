<?php

namespace LaravelMonitor\Livewire\Concerns;

use Illuminate\Support\Collection;

use function in_array;

/**
 * The "View all / ≥ Avg / ≥ P95 / ≥ Threshold" tabs above a list, same
 * behaviour as Livewire\Requests. The using class declares DURATION_FILTERS.
 */
trait FiltersByDuration
{
    public string $durationFilter = 'all';

    /**
     * A real method (not wire:click="$set(...)") so the tab buttons can
     * target it by call signature for their own wire:loading spinner.
     */
    public function setDurationFilter(string $value): void
    {
        $this->durationFilter = in_array($value, static::DURATION_FILTERS, true) ? $value : 'all';
        $this->page = 1;
    }

    /**
     * Applies the active tab to $rows. Badge counts are computed before the
     * tab itself is applied, so every tab shows its count against the same
     * base set.
     *
     * @param  Collection<int, object>  $rows
     * @param  float|int|null  $avg  the period's overall avg, the ≥ Avg bar
     * @param  float|int|null  $p95  the period's overall p95, the ≥ P95 bar
     * @return array{0: Collection<int, object>, 1: string, 2: array<string, int>} rows, active tab, badge counts
     */
    protected function filterByDuration(Collection $rows, string $avgField, string $p95Field, float|int|null $avg, float|int|null $p95, float|int $threshold): array
    {
        $atOrAboveAvg = fn ($row) => ($row->{$avgField} ?? 0) >= ($avg ?? 0);
        $atOrAboveP95 = fn ($row) => ($row->{$p95Field} ?? 0) >= ($p95 ?? 0);
        $atOrAboveThreshold = fn ($row) => ($row->{$avgField} ?? 0) >= $threshold || ($row->{$p95Field} ?? 0) >= $threshold;

        $counts = [
            'all' => $rows->count(),
            'avg' => $rows->filter($atOrAboveAvg)->count(),
            'p95' => $rows->filter($atOrAboveP95)->count(),
            'threshold' => $rows->filter($atOrAboveThreshold)->count(),
        ];

        $active = in_array($this->durationFilter, static::DURATION_FILTERS, true) ? $this->durationFilter : 'all';

        $rows = match ($active) {
            'avg' => $rows->filter($atOrAboveAvg)->values(),
            'p95' => $rows->filter($atOrAboveP95)->values(),
            'threshold' => $rows->filter($atOrAboveThreshold)->values(),
            default => $rows,
        };

        return [$rows, $active, $counts];
    }
}
