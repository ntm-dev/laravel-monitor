<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Support\Sql;

class QueryDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    protected function view(): string
    {
        return 'monitor::livewire.query-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;
        $key = $this->key;

        $entries = $storage->recent('slow_query', $since, 50, null, $key, $until);

        // One batched lookup instead of a findByRequestId() per row.
        $requestIds = $entries->pluck('request_id')->filter()->unique()->values()->all();
        $requestLabels = $storage->requestLabels($requestIds);

        $stats = $storage->stats('slow_query', $since, null, $key, $until);

        return [
            'calls' => $stats->count,
            'totalTime' => $stats->total_duration,
            'callBuckets' => $storage->countsPerBucket('slow_query', $since, $buckets, null, $key, $until),
            'duration' => $storage->durationStats('slow_query', $since, $buckets, $key, null, $until),
            'entries' => $entries,
            'requestLabels' => $requestLabels,
            'isWrite' => Sql::isWrite($key),
            'firstSeen' => $storage->firstSeen('slow_query', $key),
            // Derived from the loaded page of entries (not the full period)
            // — a quick summary, not an exhaustive audit.
            'connections' => $entries->pluck('payload.connection')->filter()->unique()->sort()->values(),
        ];
    }
}
