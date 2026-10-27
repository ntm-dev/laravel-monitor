<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Str;
use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Livewire\Concerns\FiltersByDuration;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\HttpStatusGroup;

class Requests extends Card
{
    use CombinesSubtypeStats;
    use FiltersByDuration;
    use ResolvesUserNames;

    public const PER_PAGE = 25;

    public const SORTABLE = ['key', 'count', 'success', 'client_errors', 'server_errors', 'avg_duration', 'p95_duration', 'max_duration', 'last_seen'];

    /** Above this many distinct methods, the merged Unmatched Route row shows "ANY" instead of listing them. */
    public const MAX_UNMATCHED_METHODS_SHOWN = 3;

    /**
     * Duration filter tabs: routes whose avg/p95 is at or above the period's
     * overall avg/p95, or whose max_duration reaches monitor.thresholds.request.
     */
    public const DURATION_FILTERS = ['all', 'avg', 'p95', 'threshold'];

    public string $search = '';

    public string $userId = '';

    public string $sortBy = 'count';

    public string $sortDirection = 'desc';

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function clearSearch(): void
    {
        $this->search = '';
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
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();
        $userId = $this->userId !== '' ? $this->userId : null;
        $threshold = (int) config('monitor.thresholds.request', 1000);

        $ok2xx = $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::Successful->value, null, $until, $userId);
        $ok3xx = $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::Redirection->value, null, $until, $userId);

        // The period's overall avg/p95, used as the duration tab bars.
        $duration = $storage->durationStats('request', $since, $buckets, null, null, $until, $userId);

        $routes = $storage->routeStats('request', $since, $until, $userId);

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $routes = $routes->filter(fn ($route) => str_contains(strtolower($route->key), $needle))->values();
        }

        // Badge counts include the search filter, not the duration filter.
        [$routes, $durationFilter, $durationFilterCounts] = $this->filterByDuration($routes, 'avg_duration', 'p95_duration', $duration->avg ?? null, $duration->p95 ?? null, $threshold, 'max_duration');

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'count';
        // last_seen sorts on its timestamp: SORT_REGULAR can't order the
        // CarbonImmutable instances routeStats() returns.
        $routes = $routes
            ->sortBy(
                fn ($route) => $sortBy === 'last_seen' ? $route->last_seen->getTimestamp() : $route->{$sortBy},
                SORT_REGULAR,
                $this->sortDirection === 'desc',
            )
            ->values();

        $total = $routes->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        // One grouped query instead of a stats() call per status group.
        $bySubtype = $storage->statsBySubtype('request', $since, $until, $userId);

        return [
            'requests' => $this->combineStats($bySubtype),
            'okRequests' => ($bySubtype->get(HttpStatusGroup::Successful->value)?->count ?? 0) + ($bySubtype->get(HttpStatusGroup::Redirection->value)?->count ?? 0),
            'clientErrors' => $bySubtype->get(HttpStatusGroup::ClientError->value)?->count ?? 0,
            'serverErrors' => $bySubtype->get(HttpStatusGroup::ServerError->value)?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::ClientError->value, null, $until, $userId),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::ServerError->value, null, $until, $userId),
            'duration' => $duration,
            'routes' => $routes->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)
                ->map($this->presentRoute(...))
                ->values(),
            'totalRoutes' => $total,
            'durationFilter' => $durationFilter,
            'durationFilterCounts' => $durationFilterCounts,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'users' => $this->userFilterOptions('request', $since, $until),
            'threshold' => $threshold,
        ];
    }

    /**
     * Add display method/path fields: "METHOD URI" split, or the capped method
     * list for the merged Unmatched Route row.
     */
    protected function presentRoute(object $route): object
    {
        if ($route->methods !== null) {
            return (object) array_merge((array) $route, [
                'method' => count($route->methods) > self::MAX_UNMATCHED_METHODS_SHOWN
                    ? 'ANY'
                    : implode('/', $route->methods),
                // Translated label only; the UNMATCHED_ROUTE key stays fixed.
                'path' => __('monitor::messages.common.unmatched_route'),
            ]);
        }

        return (object) array_merge((array) $route, [
            'method' => Str::before($route->key, ' '),
            'path' => Str::after($route->key, ' '),
        ]);
    }
}
