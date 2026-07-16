<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;

class Requests extends Card
{
    use CombinesSubtypeStats;
    use ResolvesUserNames;

    public const PER_PAGE = 15;

    public const SORTABLE = ['key', 'count', 'success', 'client_errors', 'server_errors', 'avg_duration', 'p95_duration'];

    public string $search = '';

    public string $userId = '';

    public string $sortBy = 'count';

    public string $sortDirection = 'desc';

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedUserId(): void
    {
        $this->page = 1;
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }

        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    protected function view(): string
    {
        return 'monitor::livewire.requests';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;
        $userId = $this->userId !== '' ? (int) $this->userId : null;

        $ok2xx = $storage->countsPerBucket('request', $since, $buckets, '2xx', null, $until, $userId);
        $ok3xx = $storage->countsPerBucket('request', $since, $buckets, '3xx', null, $until, $userId);

        $routes = $storage->routeStats('request', $since, $until, $userId);

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $routes = $routes->filter(fn ($route) => str_contains(strtolower($route->key), $needle))->values();
        }

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'count';
        $routes = $routes->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $routes->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $topUsers = $storage->topUsers('request', $since, 100, $until);
        $names = $this->resolveNames($topUsers->pluck('user_id')->all());

        // One query grouped by subtype instead of five separate stats()
        // calls (total + 2xx/3xx/4xx/5xx) — see Overview.php.
        $bySubtype = $storage->statsBySubtype('request', $since, $until, $userId);

        return [
            'requests' => $this->combineStats($bySubtype),
            'okRequests' => ($bySubtype->get('2xx')?->count ?? 0) + ($bySubtype->get('3xx')?->count ?? 0),
            'clientErrors' => $bySubtype->get('4xx')?->count ?? 0,
            'serverErrors' => $bySubtype->get('5xx')?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '4xx', null, $until, $userId),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, '5xx', null, $until, $userId),
            'duration' => $storage->durationStats('request', $since, $buckets, null, null, $until, $userId),
            'routes' => $routes->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalRoutes' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'users' => $topUsers->map(fn ($user) => (object) [
                'id' => $user->user_id,
                'name' => $names[$user->user_id] ?? "User #{$user->user_id}",
            ]),
            'threshold' => (int) config('monitor.thresholds.request', 1000),
        ];
    }
}
