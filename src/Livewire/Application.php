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
        $buckets = self::CHART_BUCKETS;
        $threshold = (int) config('monitor.thresholds.request', 1000);

        $slowRoutes = $storage->aggregateByKey('request', $since, null, 50, 'max_duration', $until)
            ->filter(fn ($route) => ($route->max_duration ?? 0) >= $threshold)
            ->values();

        return [
            'exceptions' => $storage->stats('exception', $since, null, null, $until)->count,
            'exceptionBuckets' => $storage->countsPerBucket('exception', $since, $buckets, null, null, $until),
            'impactedUsers' => $storage->topUsers('exception', $since, 100, $until)->count(),
            'slowRoutes' => $slowRoutes->take(3),
            'slowRouteCount' => $slowRoutes->count(),
            'threshold' => $threshold,
            'queuedJobs' => $storage->stats('job', $since, 'queued', null, $until)->count,
            'processedJobs' => $storage->stats('job', $since, 'processed', null, $until)->count,
            'failedJobs' => $storage->stats('job', $since, 'failed', null, $until)->count,
            'queuedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'queued', null, $until),
            'processedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'processed', null, $until),
            'failedBuckets' => $storage->countsPerBucket('job', $since, $buckets, 'failed', null, $until),
            'jobDuration' => $storage->durationStats('job', $since, $buckets, null, null, $until),
        ];
    }
}
