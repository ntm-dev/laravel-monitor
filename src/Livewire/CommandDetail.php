<?php

namespace LaravelMonitor\Livewire;

class CommandDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.command-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;
        $key = $this->key;

        // One query grouped by subtype instead of two separate stats()
        // calls (success/failed) — see Livewire/Overview.php.
        $bySubtype = $storage->statsBySubtype('command', $since, $until, key: $key);

        return [
            'success' => $bySubtype->get('success')?->count ?? 0,
            'failed' => $bySubtype->get('failed')?->count ?? 0,
            'successBuckets' => $storage->countsPerBucket('command', $since, $buckets, 'success', $key, $until),
            'failedBuckets' => $storage->countsPerBucket('command', $since, $buckets, 'failed', $key, $until),
            'duration' => $storage->durationStats('command', $since, $buckets, $key, null, $until),
            'entries' => $storage->recent('command', $since, 50, null, $key, $until),
            'threshold' => (int) config('monitor.thresholds.command', 1000),
        ];
    }
}
