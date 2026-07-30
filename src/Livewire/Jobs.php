<?php

namespace LaravelMonitor\Livewire;

class Jobs extends Card
{
    public const PER_PAGE = 15;

    public const SORTABLE = ['key', 'queued', 'processed', 'released', 'failed', 'avg_duration'];

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

    public string $sortBy = 'processed';

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
        return 'monitor::livewire.jobs';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();

        $bySubtype = $storage->statsBySubtype('job', $since, $until);

        $processed = $storage->aggregateByKey('job', $since, 'processed', self::MAX_KEYS, 'count', $until);
        $failed = $storage->aggregateByKey('job', $since, 'failed', self::MAX_KEYS, 'count', $until);
        $released = $storage->aggregateByKey('job', $since, 'released', self::MAX_KEYS, 'count', $until);
        $queued = $storage->aggregateByKey('job', $since, 'queued', self::MAX_KEYS, 'count', $until);

        $jobs = collect();

        foreach ([$processed, $failed, $released, $queued] as $index => $groups) {
            $column = ['processed', 'failed', 'released', 'queued'][$index];

            foreach ($groups as $group) {
                $job = $jobs->get($group->key) ?? (object) [
                    'key' => $group->key,
                    'queued' => 0,
                    'processed' => 0,
                    'failed' => 0,
                    'released' => 0,
                    'avg_duration' => null,
                ];

                $job->{$column} = $group->count;

                if ($column === 'processed') {
                    $job->avg_duration = $group->avg_duration;
                }

                $jobs->put($group->key, $job);
            }
        }

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $jobs = $jobs->filter(fn ($job) => str_contains(strtolower($job->key), $needle))->values();
        }

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'processed';
        $jobs = $jobs->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $jobs->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'queued' => $bySubtype->get('queued')?->count ?? 0,
            'processed' => $bySubtype->get('processed')?->count ?? 0,
            'failed' => $bySubtype->get('failed')?->count ?? 0,
            'released' => $bySubtype->get('released')?->count ?? 0,
            'queuedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'queued', null, $until),
            'processedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'processed', null, $until),
            'failedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'failed', null, $until),
            'releasedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'released', null, $until),
            'duration' => $storage->durationStats('job', $since, $buckets, null, null, $until),
            'jobs' => $jobs->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalJobs' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => (int) config('monitor.thresholds.job', 1000),
        ];
    }
}
