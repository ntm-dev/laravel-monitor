<?php

namespace LaravelMonitor\Livewire;

class CacheStats extends Card
{
    public const PER_PAGE = 15;

    public const SORTABLE = ['key', 'hit_ratio', 'hits', 'misses', 'writes', 'deletes', 'failures', 'total'];

    public string $sortBy = 'total';

    public string $sortDirection = 'desc';

    public int $page = 1;

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
        return 'monitor::livewire.cache';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;

        $hitBuckets = $storage->countsPerBucket('cache', $since, $buckets, 'hit', null, $until);
        $missBuckets = $storage->countsPerBucket('cache', $since, $buckets, 'miss', null, $until);
        $writeBuckets = $storage->countsPerBucket('cache', $since, $buckets, 'write', null, $until);
        $deleteBuckets = $storage->countsPerBucket('cache', $since, $buckets, 'forget', null, $until);
        $writeFailedBuckets = $storage->countsPerBucket('cache', $since, $buckets, 'write_failed', null, $until);
        $forgetFailedBuckets = $storage->countsPerBucket('cache', $since, $buckets, 'forget_failed', null, $until);

        $hits = array_sum($hitBuckets);
        $misses = array_sum($missBuckets);
        $writes = array_sum($writeBuckets);
        $deletes = array_sum($deleteBuckets);
        $writeFailures = array_sum($writeFailedBuckets);
        $forgetFailures = array_sum($forgetFailedBuckets);

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'total';
        $keys = $storage->cacheKeyStats($since, $until)
            ->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')
            ->values();

        $total = $keys->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'events' => $hits + $misses + $writes + $deletes,
            'eventSeries' => [
                ['label' => 'Hits', 'dot' => 'bg-emerald-500', 'total' => $hits, 'data' => $hitBuckets],
                ['label' => 'Misses', 'dot' => 'bg-amber-500', 'total' => $misses, 'data' => $missBuckets, 'warn' => true],
                ['label' => 'Writes', 'dot' => 'bg-blue-500', 'total' => $writes, 'data' => $writeBuckets],
                ['label' => 'Deletes', 'dot' => 'bg-neutral-400 dark:bg-neutral-500', 'total' => $deletes, 'data' => $deleteBuckets],
            ],
            'failures' => $writeFailures + $forgetFailures,
            'failureSeries' => [
                ['label' => 'Write', 'dot' => 'bg-rose-500', 'total' => $writeFailures, 'data' => $writeFailedBuckets, 'warn' => true],
                ['label' => 'Delete', 'dot' => 'bg-rose-400', 'total' => $forgetFailures, 'data' => $forgetFailedBuckets, 'warn' => true],
            ],
            'keys' => $keys->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalKeys' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }
}
