<?php

namespace LaravelMonitor\Livewire;

class Queries extends Card
{
    public const PER_PAGE = 15;

    public const SORTABLE = ['key', 'connection', 'calls', 'total', 'avg', 'p95'];

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
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;

        $queries = $storage->queryStats($since, $until);

        // Connection list is derived from the current period's data rather
        // than a separate query — cheap since queryStats() already fetched
        // everything, and it only ever lists connections actually in use.
        $connections = $queries->pluck('connection')->unique()->sort()->values();

        if ($this->connection !== '') {
            $queries = $queries->where('connection', $this->connection)->values();
        }

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $queries = $queries->filter(fn ($query) => str_contains(strtolower($query->key), $needle))->values();
        }

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'total';
        $queries = $queries->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $queries->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            // Calls/duration charts summarize every query regardless of the
            // connection filter — filtering the time-bucketed charts by a
            // JSON-payload field isn't worth the extra query complexity for
            // a secondary UI control.
            'calls' => $storage->stats('slow_query', $since, null, null, $until)->count,
            'callBuckets' => $storage->countsPerBucket('slow_query', $since, $buckets, null, null, $until),
            'duration' => $storage->durationStats('slow_query', $since, $buckets, null, null, $until),
            'connections' => $connections,
            'queries' => $queries->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalQueries' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }
}
