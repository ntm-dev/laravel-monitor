<?php

namespace LaravelMonitor\Livewire;

class Schedule extends Card
{
    public const PER_PAGE = 15;

    public const SORTABLE = ['key', 'finished', 'skipped', 'failed', 'avg_duration'];

    /** See Jobs::MAX_KEYS for why this replaces a plain top-N cap. */
    protected const MAX_KEYS = 5000;

    public string $search = '';

    public string $sortBy = 'finished';

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
        return 'monitor::livewire.schedule';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();

        $bySubtype = $storage->statsBySubtype('scheduled_task', $since, $until);

        $finished = $storage->aggregateByKey('scheduled_task', $since, 'finished', self::MAX_KEYS, 'count', $until);
        $failed = $storage->aggregateByKey('scheduled_task', $since, 'failed', self::MAX_KEYS, 'count', $until);
        $skipped = $storage->aggregateByKey('scheduled_task', $since, 'skipped', self::MAX_KEYS, 'count', $until);

        $tasks = collect();

        foreach ([$finished, $failed, $skipped] as $index => $groups) {
            $column = ['finished', 'failed', 'skipped'][$index];

            foreach ($groups as $group) {
                $task = $tasks->get($group->key) ?? (object) [
                    'key' => $group->key,
                    'finished' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'avg_duration' => null,
                ];

                $task->{$column} = $group->count;

                if ($column === 'finished') {
                    $task->avg_duration = $group->avg_duration;
                }

                $tasks->put($group->key, $task);
            }
        }

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $tasks = $tasks->filter(fn ($task) => str_contains(strtolower($task->key), $needle))->values();
        }

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'finished';
        $tasks = $tasks->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $tasks->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'finished' => $bySubtype->get('finished')?->count ?? 0,
            'failed' => $bySubtype->get('failed')?->count ?? 0,
            'skipped' => $bySubtype->get('skipped')?->count ?? 0,
            'finishedBuckets' => $storage->countsPerBucket('scheduled_task', $since, $buckets, 'finished', null, $until),
            'failedBuckets' => $storage->countsPerBucket('scheduled_task', $since, $buckets, 'failed', null, $until),
            'skippedBuckets' => $storage->countsPerBucket('scheduled_task', $since, $buckets, 'skipped', null, $until),
            'duration' => $storage->durationStats('scheduled_task', $since, $buckets, null, 'finished', $until),
            'tasks' => $tasks->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalTasks' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }
}
