<?php

namespace LaravelMonitor\Livewire;

class Overview extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.overview';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;

        $ok2xx = $storage->countsPerBucket('request', $since, $buckets, '2xx', null, $until);
        $ok3xx = $storage->countsPerBucket('request', $since, $buckets, '3xx', null, $until);

        return [
            'requests' => $storage->stats('request', $since, null, null, $until),
            'okRequests' => $storage->stats('request', $since, '2xx', null, $until)->count
                + $storage->stats('request', $since, '3xx', null, $until)->count,
            'clientErrors' => $storage->stats('request', $since, '4xx', null, $until)->count,
            'serverErrors' => $storage->stats('request', $since, '5xx', null, $until)->count,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '4xx', null, $until),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '5xx', null, $until),
            'duration' => $storage->durationStats('request', $since, $buckets, null, null, $until),
        ];
    }
}
