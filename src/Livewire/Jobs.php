<?php

namespace LaravelMonitor\Livewire;

class Jobs extends Card
{
    public function render()
    {
        $since = $this->since();
        $storage = $this->storage();

        $processed = $storage->aggregateByKey('job', $since, 'processed', 50);
        $failed = $storage->aggregateByKey('job', $since, 'failed', 50);
        $queued = $storage->aggregateByKey('job', $since, 'queued', 50);

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

        return view('monitor::livewire.jobs', [
            'jobs' => $jobs->sortByDesc(fn ($job) => $job->processed + $job->failed)->take(10)->values(),
        ]);
    }
}
