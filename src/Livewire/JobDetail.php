<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Collection;

class JobDetail extends Card
{
    public const PER_PAGE = 25;

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
        $storage = $this->aggregateStorage();
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
            'entries' => $this->withoutSupersededQueuedRows(
                $this->timelineStorage()->recent('job', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE)
            ),
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => (int) config('monitor.thresholds.job', 1000),
        ];
    }

    /**
     * Drops a 'queued' row once this page also has the outcome (processed/
     * failed/released) it was dispatch-time placeholder for — both share the
     * queue driver's own job_id (see Recorders\Jobs::recordQueued()'s
     * `job_id` payload field; empty for the sync connection, so this is a
     * no-op there). Without this, a job that both dispatched and finished
     * inside the same period reads as two separate, unrelated rows instead
     * of one attempt whose status simply hasn't landed yet — its outcome
     * row is left untouched, since a retried job can still produce more than
     * one of those for the same job_id (released, then eventually processed).
     */
    protected function withoutSupersededQueuedRows(Collection $entries): Collection
    {
        $jobIdsWithOutcome = $entries
            ->filter(fn ($entry) => $entry->subtype !== 'queued')
            ->map(fn ($entry) => $entry->payload['job_id'] ?? null)
            ->filter()
            ->flip();

        return $entries
            ->reject(function ($entry) use ($jobIdsWithOutcome) {
                $jobId = $entry->payload['job_id'] ?? null;

                return $entry->subtype === 'queued' && $jobId !== null && $jobIdsWithOutcome->has($jobId);
            })
            ->values();
    }
}
