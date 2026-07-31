<?php

namespace LaravelMonitor\Livewire;

class Commands extends Card
{
    public const PER_PAGE = 15;

    public const SORTABLE = ['key', 'success', 'failed', 'avg_duration'];

    /** See Jobs::MAX_KEYS for why this replaces the previous top-10 cap. */
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
        $storage = $this->storage();
        $buckets = $this->chartBuckets();

        $bySubtype = $storage->statsBySubtype('command', $since, $until);

        $success = $storage->aggregateByKey('command', $since, 'success', self::MAX_KEYS, 'count', $until);
        $failed = $storage->aggregateByKey('command', $since, 'failed', self::MAX_KEYS, 'count', $until);

        $commands = collect();

        foreach ([$success, $failed] as $index => $groups) {
            $column = ['success', 'failed'][$index];

            foreach ($groups as $group) {
                $command = $commands->get($group->key) ?? (object) [
                    'key' => $group->key,
                    'success' => 0,
                    'failed' => 0,
                    'avg_duration' => null,
                ];

                $command->{$column} = $group->count;

                if ($column === 'success') {
                    $command->avg_duration = $group->avg_duration;
                }

                $commands->put($group->key, $command);
            }
        }

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
