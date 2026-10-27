<?php

namespace LaravelMonitor\Livewire;

class Commands extends Card
{
    public const PER_PAGE = 25;

    public const SORTABLE = ['key', 'success', 'failed', 'total', 'avg_duration', 'p95_duration'];

    /** Same per-subtype cap as Jobs::MAX_KEYS. */
    protected const MAX_KEYS = 5000;

    public string $search = '';

    public string $sortBy = 'success';

    public string $sortDirection = 'desc';

    public int $page = 1;

    public function updatedSearch(): void
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
        return 'monitor::livewire.commands';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();

        $bySubtype = $storage->statsBySubtype('command', $since, $until);

        // keyStats() for totals/avg/p95 (p95 isn't computable in SQL); failed
        // comes from aggregateByKey() and success is the remainder.
        $groups = $storage->keyStats('command', $since, $until);
        $failed = $storage->aggregateByKey('command', $since, 'failed', self::MAX_KEYS, 'count', $until)->keyBy('key');

        $commands = $groups->map(function (object $group) use ($failed) {
            // Clamped: both counts are sampled independently.
            $failedCount = (int) ($failed->get($group->key)?->count ?? 0);

            return (object) [
                'key' => $group->key,
                'success' => max(0, $group->count - $failedCount),
                'failed' => $failedCount,
                'total' => $group->count,
                'avg_duration' => $group->avg_duration,
                'p95_duration' => $group->p95_duration,
            ];
        })->values();

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $commands = $commands->filter(fn ($command) => str_contains(strtolower($command->key), $needle))->values();
        }

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'success';
        $commands = $commands->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $commands->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'success' => $bySubtype->get('success')?->count ?? 0,
            'failed' => $bySubtype->get('failed')?->count ?? 0,
            'successBuckets' => $storage->countsPerBucket('command', $since, $buckets, 'success', null, $until),
            'failedBuckets' => $storage->countsPerBucket('command', $since, $buckets, 'failed', null, $until),
            'duration' => $storage->durationStats('command', $since, $buckets, null, null, $until),
            'commands' => $commands->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalCommands' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => (int) config('monitor.thresholds.command', 1000),
        ];
    }
}
