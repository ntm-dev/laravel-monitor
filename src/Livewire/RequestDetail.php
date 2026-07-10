<?php

namespace LaravelMonitor\Livewire;

class RequestDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.request-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;
        $key = $this->key;

        $ok2xx = $storage->countsPerBucket('request', $since, $buckets, '2xx', $key, $until);
        $ok3xx = $storage->countsPerBucket('request', $since, $buckets, '3xx', $key, $until);

        return [
            'stats' => $storage->stats('request', $since, null, $key, $until),
            'okRequests' => $storage->stats('request', $since, '2xx', $key, $until)->count
                + $storage->stats('request', $since, '3xx', $key, $until)->count,
            'clientErrors' => $storage->stats('request', $since, '4xx', $key, $until)->count,
            'serverErrors' => $storage->stats('request', $since, '5xx', $key, $until)->count,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '4xx', $key, $until),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '5xx', $key, $until),
            'duration' => $storage->durationStats('request', $since, $buckets, $key, null, $until),
            'entries' => $storage->recent('request', $since, 50, null, $key, $until),
            'threshold' => (int) config('monitor.thresholds.request', 1000),
        ];
    }
}
