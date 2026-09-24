<?php

namespace LaravelMonitor\Livewire;

use DateTimeInterface;
use Illuminate\Support\Collection;

class JobDetail extends Card
{
    public const PER_PAGE = 25;

    /**
     * Raw rows fetched per groupedRuns() call before collapsing to one row per
     * job_id — same cap-not-a-second-query tradeoff as Livewire\Jobs::MAX_KEYS:
     * a run whose job_id first appears past this cap is invisible to
     * groupedRuns() (undercounts totalEntries/pending pages) rather than
     * costing a second, unbounded query to find it.
     */
    protected const MAX_RAW_ENTRIES = 5000;

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

        $groups = $this->groupedRuns($since, $until, $key);
        $totalEntries = $groups->count();
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
            'entries' => $groups->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => (int) config('monitor.thresholds.job', 1000),
        ];
    }

    /**
     * Collapses every row belonging to the same dispatch (payload['job_id'],
     * shared by its 'queued' placeholder and every attempt it went through —
     * see Recorders\Jobs::recordQueued()/recordProcessing()) down to just
     * its single most current row, instead of listing a 'processing' row and
     * its eventual processed/released/failed outcome as two separate,
     * unrelated runs. recent() already orders newest-first, so the first row
     * seen for a job_id is that dispatch's current status, and its own
     * payload['attempts'] is already the right attempt count to show
     * alongside it — no separate counting pass needed. A row with no job_id
     * (predates that payload field, or the sync connection, which never
     * fires JobQueued/gets a uuid) falls back to its own row id so it never
     * collapses into an unrelated row.
     */
    protected function groupedRuns(DateTimeInterface $since, ?DateTimeInterface $until, string $key): Collection
    {
        return $this->timelineStorage()
            ->recent('job', $since, self::MAX_RAW_ENTRIES, null, $key, $until)
            ->unique(fn (object $entry) => $entry->payload['job_id'] ?? 'row-'.$entry->id)
            ->values();
    }
}
