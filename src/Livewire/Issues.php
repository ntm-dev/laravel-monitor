<?php

namespace LaravelMonitor\Livewire;

class Issues extends Card
{
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
        $threshold = (int) config('monitor.thresholds.request', 1000);

        $exceptions = $storage->aggregateByKey('exception', $since, null, 50, 'last_seen', $until);

        $latest = $storage->recent('exception', $since, 100, null, null, $until)
            ->groupBy('key')
            ->map(fn ($entries) => $entries->first());

        $exceptions = $exceptions->map(function ($group) use ($latest) {
            $group->latest = $latest->get($group->key)?->payload ?? [];

            return $group;
        });

        $slowRoutes = $storage->aggregateByKey('request', $since, null, 50, 'max_duration', $until)
            ->filter(fn ($route) => ($route->max_duration ?? 0) >= $threshold)
            ->values();

        if ($this->search !== '') {
            $exceptions = $exceptions
                ->filter(fn ($group) => stripos($group->key, $this->search) !== false
                    || stripos($group->latest['message'] ?? '', $this->search) !== false)
                ->values();
            $slowRoutes = $slowRoutes
                ->filter(fn ($route) => stripos($route->key, $this->search) !== false)
                ->values();
        }

        return [
            'exceptions' => $exceptions,
            'exceptionCount' => $exceptions->count(),
            'slowRoutes' => $slowRoutes,
            'slowRouteCount' => $slowRoutes->count(),
            'threshold' => $threshold,
        ];
    }
}
