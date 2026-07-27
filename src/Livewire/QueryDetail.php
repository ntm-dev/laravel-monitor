<?php

namespace LaravelMonitor\Livewire;

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
        $buckets = $this->chartBuckets();
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
            'firstSeen' => $storage->firstSeen('slow_query', $key),
            // Derived from the loaded page of entries (not the full period)
            // — a quick summary, not an exhaustive audit. One row per
            // distinct connection name; 'type' is only set when every
            // sampled call against that connection agreed on the same PDO
            // role (see DatabaseStorage::queryStats() for why a connection
            // can carry more than one).
            'connections' => $entries
                ->pluck('payload.connection')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->map(function (string $connection) use ($entries) {
                    $types = $entries
                        ->where('payload.connection', $connection)
                        ->pluck('payload.connection_type')
                        ->filter()
                        ->unique();

                    return ['name' => $connection, 'type' => $types->count() === 1 ? $types->first() : null];
                }),
        ];
    }
}
