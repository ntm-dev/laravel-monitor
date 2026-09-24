<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Recorders\Jobs as JobRecorder;

class Jobs extends Card
{
    use ResolvesUserNames;

    public const PER_PAGE = 25;

    public const SORTABLE = ['key', 'total', 'dispatched', 'pending', 'processing', 'processed', 'released', 'failed', 'avg_duration', 'p95_duration', 'last_seen'];

    /**
     * Per-subtype grouped rows are capped here rather than at the previous
     * top-10 default: unlike a route table (routeStats() samples the raw
     * table once and groups every key it sees), aggregateByKey() applies its
     * LIMIT in SQL per subtype, so a low cap would silently drop job classes
     * that rank outside the top N of one subtype even though they're common
     * overall — e.g. a class with many "queued" rows but few "processed"
     * ones. This is comfortably above any real app's distinct job-class
     * count while still bounded.
     */
    protected const MAX_KEYS = 5000;

    public string $search = '';

    public string $userId = '';

    public string $sortBy = 'last_seen';

    public string $sortDirection = 'desc';

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedUserId(): void
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
        return 'monitor::livewire.jobs';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();
        $userId = $this->userId !== '' ? $this->userId : null;

        $bySubtype = $storage->statsBySubtype('job', $since, $until, $userId);

        $processed = $storage->aggregateByKey('job', $since, 'processed', self::MAX_KEYS, 'count', $until, $userId);
        $failed = $storage->aggregateByKey('job', $since, 'failed', self::MAX_KEYS, 'count', $until, $userId);
        $released = $storage->aggregateByKey('job', $since, 'released', self::MAX_KEYS, 'count', $until, $userId);
        // The initial add to the queue only (see Recorders\Jobs::DISPATCH) —
        // a retry re-added by $released below isn't a second "dispatch".
        $dispatched = $storage->aggregateByKey('job', $since, JobRecorder::DISPATCH, self::MAX_KEYS, 'count', $until, $userId);
        $processing = $storage->aggregateByKey('job', $since, JobRecorder::PROCESSING, self::MAX_KEYS, 'count', $until, $userId);
        // p95_duration isn't computable in SQL (see keyStats()'s own docs),
        // so it comes from its own sampled pass rather than the aggregateByKey()
        // calls above — scoped to 'processed' the same way avg_duration
        // already is below, since a queued/released/failed row carries no
        // meaningful processing duration to mix in.
        $p95ByKey = $storage->keyStats('job', $since, $until, $userId, 'processed')->keyBy('key');

        $jobs = collect();

        foreach ([$processed, $failed, $released, $dispatched, $processing] as $index => $groups) {
            $column = ['processed', 'failed', 'released', 'dispatched', 'processing'][$index];

            foreach ($groups as $group) {
                $job = $jobs->get($group->key) ?? (object) [
                    'key' => $group->key,
                    'dispatched' => 0,
                    'processing' => 0,
                    'processed' => 0,
                    'failed' => 0,
                    'released' => 0,
                    'avg_duration' => null,
                    'p95_duration' => null,
                    'last_seen' => null,
                ];

                $job->{$column} = $group->count;

                if ($column === 'processed') {
                    $job->avg_duration = $group->avg_duration;
                    $job->p95_duration = $p95ByKey->get($group->key)?->p95_duration;
                }

                // Latest activity across every subtype (dispatched/processing/
                // processed/released/failed), not just this one column's own
                // sample — aggregateByKey() already returns each group's own
                // max(created_at) as last_seen.
                if ($job->last_seen === null || $group->last_seen?->greaterThan($job->last_seen)) {
                    $job->last_seen = $group->last_seen;
                }

                $jobs->put($group->key, $job);
            }
        }

        // 'total' is every time this key entered the queue — the initial
        // dispatch plus every retry re-added by a release (see
        // Recorders\Jobs::DISPATCH/recordReleased()).
        // 'pending' is whatever's still sitting in the queue, never
        // resolved into a JobProcessing pickup (see Recorders\Jobs::PROCESSING)
        // — a fresh dispatch nobody has picked up yet, or a released retry
        // waiting to be picked up again — clamped rather than trusted to
        // stay non-negative, since each count is independently sampled at
        // high volume (see aggregateByKey()'s own docs on maxSampleRows()).
        //
        // $job->processing itself still holds the raw pickup count here
        // (every JobProcessing this key ever recorded) — pending needs that
        // raw count to know how much of $job->total has ever been picked up
        // at all. Only once pending is settled does the loop below overwrite
        // $job->processing down to just the attempts still in flight right
        // now, by netting out whichever of those pickups already resolved.
        foreach ($jobs as $job) {
            $job->total = $job->dispatched + $job->released;
            $job->pending = max(0, $job->total - $job->processing);
            $job->processing = max(0, $job->processing - $job->processed - $job->released - $job->failed);
        }

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $jobs = $jobs->filter(fn ($job) => str_contains(strtolower($job->key), $needle))->values();
        }

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'last_seen';
        // last_seen sorts on its timestamp: SORT_REGULAR can't order the
        // CarbonImmutable instances aggregateByKey() returns.
        $jobs = $jobs
            ->sortBy(
                fn ($job) => $sortBy === 'last_seen' ? $job->last_seen?->getTimestamp() ?? 0 : $job->{$sortBy},
                SORT_REGULAR,
                $this->sortDirection === 'desc',
            )
            ->values();

        $total = $jobs->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'queued' => $bySubtype->get('queued')?->count ?? 0,
            'processed' => $bySubtype->get('processed')?->count ?? 0,
            'failed' => $bySubtype->get('failed')?->count ?? 0,
            'released' => $bySubtype->get('released')?->count ?? 0,
            'queuedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'queued', null, $until, $userId),
            'processedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'processed', null, $until, $userId),
            'failedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'failed', null, $until, $userId),
            'releasedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'released', null, $until, $userId),
            'duration' => $storage->durationStats('job', $since, $buckets, null, null, $until, $userId),
            'jobs' => $jobs->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalJobs' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'users' => $this->userFilterOptions('job', $since, $until),
            'threshold' => (int) config('monitor.thresholds.job', 1000),
        ];
    }
}
