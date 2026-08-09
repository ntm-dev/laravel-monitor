<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Support\Cron;

class Schedule extends Card
{
    public const PER_PAGE = 15;

    public const SORTABLE = [
        'key', 'next_run_at', 'finished', 'skipped',
        'failed', 'total', 'avg_duration', 'p95_duration',
    ];

    /** See Jobs::MAX_KEYS for why this replaces a plain top-N cap. */
    protected const MAX_KEYS = 5000;

    public string $search = '';

    public string $sortBy = 'key';

    public string $sortDirection = 'asc';

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

        // p95 can't be computed in SQL portably, so it comes from the sampled
        // per-key breakdown rather than from aggregateByKey() above; scoped to
        // `finished` to match avg_duration, since failed/skipped runs record
        // no duration at all.
        $durations = $storage->keyStats('scheduled_task', $since, $until, subtype: 'finished')->keyBy('key');

        // Each task's own cron expression, off the payload of its latest run.
        $definitions = $storage->latestPayloadByKey('scheduled_task', $since, $until, self::MAX_KEYS);

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

        foreach ($tasks as $task) {
            $payload = $definitions->get($task->key, []);

            $task->total = $task->finished + $task->failed + $task->skipped;
            $task->p95_duration = $durations->get($task->key)?->p95_duration;
            $task->command = $payload['command'] ?? null;
            $task->expression = $payload['expression'] ?? null;
            $task->schedule = Cron::describe($task->expression, $payload['repeat_seconds'] ?? null);
            $task->next_run_at = Cron::nextRunAt(
                $task->expression,
                $payload['timezone'] ?? null,
                $payload['repeat_seconds'] ?? null,
            );
        }

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $tasks = $tasks->filter(static fn ($task) => str_contains(strtolower($task->key), $needle)
                || str_contains(strtolower($task->command ?? ''), $needle))->values();
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
