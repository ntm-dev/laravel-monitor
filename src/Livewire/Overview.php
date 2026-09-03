<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Support\HttpStatusGroup;

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
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();

        $ok2xx = $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::Successful->value, null, $until);
        $ok3xx = $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::Redirection->value, null, $until);

        // One query grouped by subtype instead of five separate stats()
        // calls (total + 2xx/3xx/4xx/5xx) — each stats() call scans the
        // same underlying rows, so calling it five times over just meant
        // paying for the same scan five times over.
        $bySubtype = $storage->statsBySubtype('request', $since, $until);

        return [
            'requests' => $this->combineStats($bySubtype),
            'okRequests' => ($bySubtype->get(HttpStatusGroup::Successful->value)?->count ?? 0) + ($bySubtype->get(HttpStatusGroup::Redirection->value)?->count ?? 0),
            'clientErrors' => $bySubtype->get(HttpStatusGroup::ClientError->value)?->count ?? 0,
            'serverErrors' => $bySubtype->get(HttpStatusGroup::ServerError->value)?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::ClientError->value, null, $until),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::ServerError->value, null, $until),
            'duration' => $storage->durationStats('request', $since, $buckets, null, null, $until),
        ];
    }
}
