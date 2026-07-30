<?php

namespace LaravelMonitor\Livewire;

class JobDetail extends Card
{
    public const PER_PAGE = 50;

    public string $key = '';

    public int $page = 1;

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
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
        return 'monitor::livewire.job-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        // One query grouped by subtype instead of four separate stats()
        // calls (queued/processed/failed/released) — see Livewire/Overview.php.
        $bySubtype = $storage->statsBySubtype('job', $since, $until, key: $key);

        $totalEntries = $bySubtype->sum('count');
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'queued' => $bySubtype->get('queued')?->count ?? 0,
            'processed' => $bySubtype->get('processed')?->count ?? 0,
            'failed' => $bySubtype->get('failed')?->count ?? 0,
            'released' => $bySubtype->get('released')?->count ?? 0,
            'queuedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'queued', $key, $until),
            'processedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'processed', $key, $until),
            'failedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'failed', $key, $until),
            'releasedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'released', $key, $until),
            'duration' => $storage->durationStats('job', $since, $buckets, $key, null, $until),
            'entries' => $storage->recent('job', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE),
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => (int) config('monitor.thresholds.job', 1000),
        ];
    }
}
