<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Str;
use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\HttpStatusGroup;

class Requests extends Card
{
    use CombinesSubtypeStats;
    use ResolvesUserNames;

    public const PER_PAGE = 25;

    public const SORTABLE = ['key', 'count', 'success', 'client_errors', 'server_errors', 'avg_duration', 'p95_duration'];

    /** Above this many distinct methods, the merged Unmatched Route row shows "ANY" instead of listing them. */
    public const MAX_UNMATCHED_METHODS_SHOWN = 3;

    /**
     * Duration filter tabs above the route table: 'all' shows every route;
     * the rest keep only routes whose own avg_duration/p95_duration is at
     * or above the given bar — the period's overall avg/p95 for 'avg'/'p95',
     * or the configured monitor.thresholds.request for 'threshold' (the
     * same bar the avg/p95 columns already highlight amber against).
     */
    public const DURATION_FILTERS = ['all', 'avg', 'p95', 'threshold'];

    public string $search = '';

    public string $durationFilter = 'all';

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

    /**
     * A real method (not wire:click="$set(...)") so the tab buttons can
     * target it precisely by call signature for their own wire:loading
     * spinner — $set()'s own call isn't reliably matchable that way.
     */
    public function setDurationFilter(string $value): void
    {
        $this->durationFilter = in_array($value, self::DURATION_FILTERS, true) ? $value : 'all';
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

        // Computed before the route list below so the >= AVG / >= P95 tabs
        // have the period's own overall avg/p95 to compare each route against.
        $duration = $storage->durationStats('request', $since, $buckets, null, null, $until, $userId);

        $routes = $storage->routeStats('request', $since, $until, $userId);

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $routes = $routes->filter(fn ($route) => str_contains(strtolower($route->key), $needle))->values();
        }

        $atOrAboveAvg = fn ($route) => ($route->avg_duration ?? 0) >= ($duration->avg ?? 0);
        $atOrAboveP95 = fn ($route) => ($route->p95_duration ?? 0) >= ($duration->p95 ?? 0);
        $atOrAboveThreshold = fn ($route) => ($route->avg_duration ?? 0) >= $threshold || ($route->p95_duration ?? 0) >= $threshold;

        // Tab badge counts reflect the search filter above but not the
        // duration filter itself, so switching tabs shows every option's
        // count against the same base set instead of just the active one.
        $durationFilterCounts = [
            'all' => $routes->count(),
            'avg' => $routes->filter($atOrAboveAvg)->count(),
            'p95' => $routes->filter($atOrAboveP95)->count(),
            'threshold' => $routes->filter($atOrAboveThreshold)->count(),
        ];

        $durationFilter = in_array($this->durationFilter, self::DURATION_FILTERS, true) ? $this->durationFilter : 'all';

        $routes = match ($durationFilter) {
            'avg' => $routes->filter($atOrAboveAvg)->values(),
            'p95' => $routes->filter($atOrAboveP95)->values(),
            'threshold' => $routes->filter($atOrAboveThreshold)->values(),
            default => $routes,
        };

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'count';
        $routes = $routes->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $routes->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $topUsers = $this->userStorage()->topUsers('request', $since, 100, $until);
        $names = $this->resolveNames($topUsers->pluck('user_id')->all());

        // One query grouped by subtype instead of five separate stats()
        // calls (total + 2xx/3xx/4xx/5xx) — see Overview.php.
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
            'users' => $topUsers->map(fn ($user) => (object) [
                'id' => $user->user_id,
                'name' => $names[$user->user_id],
            ]),
            'threshold' => $threshold,
        ];
    }

    /**
     * Adds display-ready method/path fields so the Blade view carries no
     * splitting logic: a plain "METHOD URI" split for an ordinary route, or
     * the capped method list for the merged Unmatched Route row (more than
     * MAX_UNMATCHED_METHODS_SHOWN distinct methods collapse to "ANY").
     */
    protected function presentRoute(object $route): object
    {
        if ($route->methods !== null) {
            return (object) array_merge((array) $route, [
                'method' => count($route->methods) > self::MAX_UNMATCHED_METHODS_SHOWN
                    ? 'ANY'
                    : implode('/', $route->methods),
                // Displayed label only — the underlying key/grouping sentinel
                // (RequestsRecorder::UNMATCHED_ROUTE) stays a fixed English
                // string regardless of locale, see resolveKeyHash()/query().
                'path' => __('monitor::messages.common.unmatched_route'),
            ]);
        }

        return (object) array_merge((array) $route, [
            'method' => Str::before($route->key, ' '),
            'path' => Str::after($route->key, ' '),
        ]);
    }
}
