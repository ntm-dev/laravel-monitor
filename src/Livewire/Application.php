<?php

namespace LaravelMonitor\Livewire;

class Application extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.application';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();
        $threshold = (int) config('monitor.thresholds.request', 1000);

        $slowRoutes = $storage->aggregateByKey('request', $since, null, 50, 'max_duration', $until)
            ->filter(fn ($route) => ($route->max_duration ?? 0) >= $threshold)
            ->values();

        // One query grouped by subtype instead of three separate stats()
        // calls (queued/processed/failed) — see Livewire/Overview.php.
        $jobsBySubtype = $storage->statsBySubtype('job', $since, $until);

        return [
            'exceptions' => $storage->stats('exception', $since, null, null, $until)->count,
            'exceptionBuckets' => $storage->countsPerBucket('exception', $since, $buckets, null, null, $until),
            'impactedUsers' => $storage->topUsers('exception', $since, 100, $until)->count(),
            'slowRoutes' => $slowRoutes->take(3),
            'slowRouteCount' => $slowRoutes->count(),
            'threshold' => $threshold,
            'queuedJobs' => $jobsBySubtype->get('queued')?->count ?? 0,
            'processedJobs' => $jobsBySubtype->get('processed')?->count ?? 0,
            'failedJobs' => $jobsBySubtype->get('failed')?->count ?? 0,
            'queuedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'queued', null, $until),
            'processedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'processed', null, $until),
            'failedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'failed', null, $until),
            'jobDuration' => $storage->durationStats('job', $since, $buckets, null, null, $until),
        ];
    }
}
