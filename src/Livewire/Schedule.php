<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use LaravelMonitor\Recorders\ScheduledTasks;
use LaravelMonitor\Support\Cron;
use LaravelMonitor\Support\Percentile;

class Schedule extends Card
{
    public const PER_PAGE = 25;

    public const SORTABLE = [
        'key', 'next_run_at', 'finished', 'skipped',
        'failed', 'total', 'avg_duration', 'p95_duration',
    ];

    /**
     * Cap on the raw rows data() pulls to group by (key, cadence). Volume is
     * bounded by configured tasks, though sub-minute tasks can hit it on
     * long periods.
     */
    protected const MAX_SAMPLE_ROWS = 20000;

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
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();

        $bySubtype = $storage->statsBySubtype('scheduled_task', $since, $until);

        // The currently registered schedule, by task => expression + repeat
        // seconds (sub-minute tasks share the `* * * * *` expression).
        $liveCadences = collect(app(ConsoleSchedule::class)->events())
            ->mapWithKeys(static fn ($event) => [
                ScheduledTasks::name($event) => "{$event->expression}\0{$event->repeatSeconds}",
            ]);

        // Grouped by (key, cadence) in PHP, which aggregateByKey() can't do,
        // so a changed cadence keeps its old history as a separate inactive row.
        $groups = [];

        foreach ($this->timelineStorage()->recent('scheduled_task', $since, self::MAX_SAMPLE_ROWS, null, null, $until) as $row) {
            $expression = $row->payload['expression'] ?? null;
            $repeatSeconds = $row->payload['repeat_seconds'] ?? null;
            $groupKey = "{$row->key}\0{$expression}\0{$repeatSeconds}";

            $group = $groups[$groupKey] ??= (object) [
                'key' => $row->key,
                'expression' => $expression,
                'repeat_seconds' => $repeatSeconds,
                'command' => $row->payload['command'] ?? null,
                'timezone' => $row->payload['timezone'] ?? null,
                'finished' => 0,
                'failed' => 0,
                'skipped' => 0,
                'durations' => [],
            ];

            if (in_array($row->subtype, ['finished', 'failed', 'skipped'], true)) {
                $group->{$row->subtype}++;
            }

            if ($row->subtype === 'finished' && $row->duration !== null) {
                $group->durations[] = (float) $row->duration;
            }
        }

        $tasks = collect();

        foreach ($groups as $group) {
            $durations = $group->durations;
            $cadence = "{$group->expression}\0{$group->repeat_seconds}";
            // has() first, or a missing key would match a row with no recorded cadence.
            $isActive = $liveCadences->has($group->key) && $liveCadences->get($group->key) === $cadence;

            $tasks->push((object) [
                'key' => $group->key,
                'command' => $group->command,
                'expression' => $group->expression,
                'repeat_seconds' => $group->repeat_seconds,
                'finished' => $group->finished,
                'failed' => $group->failed,
                'skipped' => $group->skipped,
                'total' => $group->finished + $group->failed + $group->skipped,
                'avg_duration' => $durations === [] ? null : round(array_sum($durations) / count($durations), 2),
                'p95_duration' => Percentile::of($durations, 0.95),
                'isActive' => $isActive,
                'schedule' => Cron::describe($group->expression, $group->repeat_seconds),
                'next_run_at' => $isActive ? Cron::nextRunAt($group->expression, $group->timezone, $group->repeat_seconds) : null,
            ]);
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
