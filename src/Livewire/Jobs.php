<?php

namespace LaravelMonitor\Livewire;

class Jobs extends Card
{
    protected function view(): string
    {
        return 'monitor::livewire.jobs';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();

        $processed = $storage->aggregateByKey('job', $since, 'processed', 50, 'count', $until);
        $failed = $storage->aggregateByKey('job', $since, 'failed', 50, 'count', $until);
        $queued = $storage->aggregateByKey('job', $since, 'queued', 50, 'count', $until);

        $jobs = collect();

        foreach ([$processed, $failed, $queued] as $index => $groups) {
            $column = ['processed', 'failed', 'queued'][$index];

            foreach ($groups as $group) {
                $job = $jobs->get($group->key) ?? (object) [
                    'key' => $group->key,
                    'queued' => 0,
                    'processed' => 0,
                    'failed' => 0,
                    'avg_duration' => null,
                ];

                $job->{$column} = $group->count;

                if ($column === 'processed') {
                    $job->avg_duration = $group->avg_duration;
                }

                $jobs->put($group->key, $job);
            }
        }

        return [
            'jobs' => $jobs->sortByDesc(fn ($job) => $job->processed + $job->failed)->take($this->limit)->values(),
            'threshold' => (int) config('monitor.thresholds.job', 1000),
        ];
    }
}
