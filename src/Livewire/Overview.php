<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;

class Overview extends Card
{
    use CombinesSubtypeStats;

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

        // One query grouped by subtype instead of five separate stats()
        // calls (total + 2xx/3xx/4xx/5xx) — each stats() call scans the
        // same underlying rows, so calling it five times over just meant
        // paying for the same scan five times over.
        $bySubtype = $storage->statsBySubtype('request', $since, $until);

        return [
            'requests' => $this->combineStats($bySubtype),
            'okRequests' => ($bySubtype->get('2xx')?->count ?? 0) + ($bySubtype->get('3xx')?->count ?? 0),
            'clientErrors' => $bySubtype->get('4xx')?->count ?? 0,
            'serverErrors' => $bySubtype->get('5xx')?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '4xx', null, $until),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '5xx', null, $until),
            'duration' => $storage->durationStats('request', $since, $buckets, null, null, $until),
        ];
    }
}
