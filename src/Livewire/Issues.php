<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Issues extends Card
{
    /**
     * Areas checked against their own configured threshold for the
     * Performance tab, in the order rows fall back to when max durations tie.
     * type => the monitor_entries `type`; tab => dashboard tab a row links to.
     */
    protected const PERFORMANCE_AREAS = [
        'request' => ['badge' => 'Request', 'tab' => 'requests', 'threshold' => 'request'],
        'job' => ['badge' => 'Job', 'tab' => 'jobs', 'threshold' => 'job'],
        'slow_query' => ['badge' => 'Query', 'tab' => 'queries', 'threshold' => 'query'],
        'outgoing_request' => ['badge' => 'Outgoing', 'tab' => 'outgoing', 'threshold' => 'outgoing_request'],
    ];

    public string $view = 'exceptions';

    public string $search = '';

    protected function view(): string
    {
        return 'monitor::livewire.issues';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();

        $exceptions = $storage->aggregateByKey('exception', $since, null, 50, 'last_seen', $until);

        $latest = $storage->recent('exception', $since, 100, null, null, $until)
            ->groupBy('key')
            ->map(fn ($entries) => $entries->first());

        $exceptions = $exceptions->map(function ($group) use ($latest) {
            $group->latest = $latest->get($group->key)?->payload ?? [];

            return $group;
        });

        $performance = $this->performanceIssues($since, $until);

        if ($this->search !== '') {
            $needle = $this->search;

            $exceptions = $exceptions
                ->filter(fn ($group) => stripos($group->key, $needle) !== false
                    || stripos($group->latest['message'] ?? '', $needle) !== false)
                ->values();
            $performance = $performance
                ->filter(fn ($item) => stripos($item->label, $needle) !== false)
                ->values();
        }

        return [
            'exceptions' => $exceptions,
            'exceptionCount' => $exceptions->count(),
            'performance' => $performance,
            'performanceCount' => $performance->count(),
        ];
    }

    /**
     * Requests, jobs, slow queries and outgoing requests whose max duration
     * breached their own configured threshold, merged into one severity-
     * ordered feed (worst max duration first) — mirroring how Nightwatch
     * surfaces every "over threshold" area as a single Issues list rather
     * than a separate page per area.
     *
     * @return Collection<int, object{type: string, badge: string, label: string, key: string, count: int, max_duration: float}>
     */
    protected function performanceIssues(\DateTimeInterface $since, ?\DateTimeInterface $until): Collection
    {
        $items = collect();

        foreach (self::PERFORMANCE_AREAS as $type => $area) {
            $threshold = (int) config("monitor.thresholds.{$area['threshold']}", 1000);

            $this->storage()
                ->aggregateByKey($type, $since, null, 50, 'max_duration', $until)
                ->filter(fn ($row) => ($row->max_duration ?? 0) >= $threshold)
                ->each(function ($row) use ($items, $type, $area) {
                    $items->push((object) [
                        'type' => $type,
                        'badge' => $area['badge'],
                        'tab' => $area['tab'],
                        'label' => $type === 'job' ? class_basename($row->key) : Str::limit($row->key, 100),
                        'key' => $row->key,
                        'count' => $row->count,
                        'max_duration' => $row->max_duration,
                    ]);
                });
        }

        return $items->sortByDesc('max_duration')->values();
    }
}
