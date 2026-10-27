<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\FiltersByDuration;

class Queries extends Card
{
    use FiltersByDuration;

    public const PER_PAGE = 25;

    public const SORTABLE = ['key', 'connection', 'calls', 'total', 'avg', 'p95', 'last_seen'];

    /** Duration filter tabs, same meaning as Requests::DURATION_FILTERS. */
    public const DURATION_FILTERS = ['all', 'avg', 'p95', 'threshold'];

    public string $search = '';

    public string $connection = '';

    public string $sortBy = 'total';

    public string $sortDirection = 'desc';

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedConnection(): void
    {
        $this->page = 1;
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }

        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    protected function view(): string
    {
        return 'monitor::livewire.queries';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();

        $queries = $this->cacheAndQueryStorage()->queryStats($since, $until);

        // Only connections used in this period, taken from queryStats().
        $connections = $queries->pluck('connection')->unique()->sort()->values();

        if ($this->connection !== '') {
            $queries = $queries->where('connection', $this->connection)->values();
        }

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $queries = $queries->filter(fn ($query) => str_contains(strtolower($query->key), $needle))->values();
        }

        // The period's overall avg/p95, used as the duration tab bars.
        $duration = $storage->durationStats('query', $since, $buckets, null, null, $until);
        $threshold = (int) config('monitor.thresholds.query', 500);

        [$queries, $durationFilter, $durationFilterCounts] = $this->filterByDuration($queries, 'avg', 'p95', $duration->avg ?? null, $duration->p95 ?? null, $threshold);

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'total';
        // last_seen sorts on its timestamp: SORT_REGULAR can't order the
        // CarbonImmutable instances queryStats() returns.
        $queries = $queries
            ->sortBy(
                fn ($query) => $sortBy === 'last_seen' ? $query->last_seen->getTimestamp() : $query->{$sortBy},
                SORT_REGULAR,
                $this->sortDirection === 'desc',
            )
            ->values();

        $total = $queries->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            // Calls/duration charts ignore the connection filter.
            'calls' => $storage->stats('query', $since, null, null, $until)->count,
            'callBuckets' => $storage->countsPerBucket('query', $since, $buckets, null, null, $until),
            'duration' => $duration,
            'threshold' => $threshold,
            'durationFilter' => $durationFilter,
            'durationFilterCounts' => $durationFilterCounts,
            'connections' => $connections,
            'queries' => $queries->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalQueries' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }
}
