<?php

namespace LaravelMonitor\Livewire;

class JobDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
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
        $buckets = self::CHART_BUCKETS;
        $key = $this->key;

        return [
            'queued' => $storage->stats('job', $since, 'queued', $key, $until)->count,
            'processed' => $storage->stats('job', $since, 'processed', $key, $until)->count,
            'failed' => $storage->stats('job', $since, 'failed', $key, $until)->count,
            'queuedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'queued', $key, $until),
            'processedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'processed', $key, $until),
            'failedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'failed', $key, $until),
            'duration' => $storage->durationStats('job', $since, $buckets, $key, null, $until),
            'entries' => $storage->recent('job', $since, 50, null, $key, $until),
        ];
    }
}
