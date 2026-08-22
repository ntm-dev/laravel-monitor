<?php

namespace LaravelMonitor\Livewire;

class ScheduleDetail extends Card
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
        return 'monitor::livewire.schedule-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        // One query grouped by subtype instead of three separate stats()
        // calls (finished/failed/skipped) — see Livewire/Overview.php.
        $bySubtype = $storage->statsBySubtype('scheduled_task', $since, $until, key: $key);

        $totalEntries = $bySubtype->sum('count');
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'finished' => $bySubtype->get('finished')?->count ?? 0,
            'failed' => $bySubtype->get('failed')?->count ?? 0,
            'skipped' => $bySubtype->get('skipped')?->count ?? 0,
            'finishedBuckets' => $storage->countsPerBucket('scheduled_task', $since, $buckets, 'finished', $key, $until),
            'failedBuckets' => $storage->countsPerBucket('scheduled_task', $since, $buckets, 'failed', $key, $until),
            'skippedBuckets' => $storage->countsPerBucket('scheduled_task', $since, $buckets, 'skipped', $key, $until),
            'duration' => $storage->durationStats('scheduled_task', $since, $buckets, $key, 'finished', $until),
            'entries' => $storage->recent('scheduled_task', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE),
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }
}
