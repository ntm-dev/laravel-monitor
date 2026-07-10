<?php

namespace LaravelMonitor\Livewire;

class Overview extends Card
{
    public function render()
    {
        $since = $this->since();
        $storage = $this->storage();

        $requests = $storage->stats('request', $since);

        return view('monitor::livewire.overview', [
            'requests' => $requests,
            'errorRequests' => $storage->stats('request', $since, '5xx')->count,
            'exceptions' => $storage->stats('exception', $since)->count,
            'slowQueries' => $storage->stats('slow_query', $since)->count,
            'failedJobs' => $storage->stats('job', $since, 'failed')->count,
            'buckets' => $storage->countsPerBucket('request', $since, 60),
        ]);
    }
}
