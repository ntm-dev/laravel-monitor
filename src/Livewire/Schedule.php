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
     * Every scheduled_task row within the selected period gets pulled
     * (payload included) to group by (key, cadence) rather than by key
     * alone — see data(). Unlike Requests/Queries, scheduled-task volume is
     * bounded by how many cron jobs a human configured, so even at a
     * pathological everySecond() this stays well short of the cap within
     * any period this dashboard offers.
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

        // `Schedule::events()` reflects whatever the *currently running* app
        // code actually registers — available in this web request the same
        // as in a console one, since scheduled tasks are normally registered
        // from a service provider's `booted()` hook, not gated to console
        // (see Recorders\ScheduledTasks::name()'s own docblock). Keyed by
        // command, expression, *and* repeat_seconds: a task whose cadence
        // changed needs its own row for the cadence no longer live, not
        // just one whose command was removed outright. repeat_seconds
        // matters on its own — everySecond()/everyFiveSeconds()/... all
        // reduce to the same 5-field `* * * * *` expression as everyMinute()
        // (see Illuminate\Console\Scheduling\ManagesFrequencies::repeatEvery()),
        // distinguished only by this separate property.
        $liveCadences = collect(app(ConsoleSchedule::class)->events())
            ->mapWithKeys(static fn ($event) => [
                ScheduledTasks::name($event) => "{$event->expression}\0{$event->repeatSeconds}",
            ]);

        // Grouped by (key, cadence) rather than by key alone — a task whose
        // cadence changed shows as two rows, its old cadence's own history
        // staying put (and marked inactive below) instead of quietly
        // merging into whatever the *latest* run happens to be scheduled
        // under. AggregateStorage::aggregateByKey()/latestPayloadByKey() only
        // group by the raw `key` column and can't make that split, so this pulls
        // every row (payload included) instead — see self::MAX_SAMPLE_ROWS
        // for why that stays cheap for this type.
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
            // has() first: a key absent from the live schedule and a group
            // with no recorded expression (pre-dating that payload field)
            // would otherwise both read as null and compare equal.
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
                // No next run to count down to when this cadence isn't the
                // live one any more — countdown.blade.php already renders
                // its own fallback for null.
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
