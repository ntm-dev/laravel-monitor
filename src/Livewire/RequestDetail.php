<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;

class RequestDetail extends Card
{
    use CombinesSubtypeStats;

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

        // One query grouped by subtype instead of five separate stats()
        // calls (total + 2xx/3xx/4xx/5xx) — see Livewire/Overview.php.
        $bySubtype = $storage->statsBySubtype('request', $since, $until, key: $key);

        return [
            'stats' => $this->combineStats($bySubtype),
            'okRequests' => ($bySubtype->get('2xx')?->count ?? 0) + ($bySubtype->get('3xx')?->count ?? 0),
            'clientErrors' => $bySubtype->get('4xx')?->count ?? 0,
            'serverErrors' => $bySubtype->get('5xx')?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '4xx', $key, $until),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '5xx', $key, $until),
            'duration' => $storage->durationStats('request', $since, $buckets, $key, null, $until),
            'entries' => $storage->recent('request', $since, 50, null, $key, $until),
            'threshold' => (int) config('monitor.thresholds.request', 1000),
        ];
    }
}
