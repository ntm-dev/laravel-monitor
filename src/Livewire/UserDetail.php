<?php

namespace LaravelMonitor\Livewire;

use Illuminate\Support\Str;
use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\HttpStatusGroup;

class UserDetail extends Card
{
    use CombinesSubtypeStats;
    use ResolvesUserNames;

    public const PER_PAGE = 25;

    /** How many rows the Top Routes / Slowest Routes ranked lists show. */
    protected const MAX_ROUTES_SHOWN = 5;

    /** Same status/duration filter tabs as Livewire\RequestDetail, scoped to this user instead of one route. */
    public const STATUS_FILTERS = ['all', 'ok', '4xx', '5xx'];

    public const DURATION_FILTERS = ['all', 'avg', 'p95', 'threshold'];

    public string $userId = '';

    public string $statusFilter = 'all';

    public string $durationFilter = 'all';

    public int $page = 1;

    public function setStatusFilter(string $value): void
    {
        $this->statusFilter = in_array($value, self::STATUS_FILTERS, true) ? $value : 'all';
        $this->page = 1;
    }

    public function setDurationFilter(string $value): void
    {
        $this->durationFilter = in_array($value, self::DURATION_FILTERS, true) ? $value : 'all';
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
        return 'monitor::livewire.user-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();
        $userId = $this->userId;
        $threshold = (int) config('monitor.thresholds.request', 1000);

        $ok2xx = $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::Successful->value, null, $until, $userId);
        $ok3xx = $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::Redirection->value, null, $until, $userId);

        // Computed before the filter tab counts below so the >= AVG / >= P95
        // tabs have this user's own overall avg/p95 to compare each entry
        // against — same approach as Livewire\RequestDetail::data().
        $duration = $storage->durationStats('request', $since, $buckets, null, null, $until, $userId);

        // One query grouped by subtype instead of five separate stats()
        // calls, same approach as Livewire\RequestDetail::data().
        $bySubtype = $storage->statsBySubtype('request', $since, $until, $userId);
        $stats = $this->combineStats($bySubtype);

        $statusFilter = in_array($this->statusFilter, self::STATUS_FILTERS, true) ? $this->statusFilter : 'all';
        $durationFilter = in_array($this->durationFilter, self::DURATION_FILTERS, true) ? $this->durationFilter : 'all';

        $statusSubtypes = [
            'all' => null,
            'ok' => [HttpStatusGroup::Informational->value, HttpStatusGroup::Successful->value, HttpStatusGroup::Redirection->value],
            '4xx' => HttpStatusGroup::ClientError->value,
            '5xx' => HttpStatusGroup::ServerError->value,
        ];

        // null for 'all' means "no duration bar" (a real, intentional
        // filter value); avg/p95 can also come back null from durationStats()
        // when the period has no duration data at all.
        $durationBars = ['all' => null, 'avg' => $duration->avg, 'p95' => $duration->p95, 'threshold' => (float) $threshold];

        $subtype = $statusSubtypes[$statusFilter];
        $minDuration = $durationBars[$durationFilter];

        // Each tab group's badges are cross-filtered against the OTHER
        // group's currently selected tab, same as Livewire\RequestDetail.
        $statusFilterCounts = [];

        foreach ($statusSubtypes as $filterKey => $filterSubtype) {
            $statusFilterCounts[$filterKey] = $filterSubtype === null && $minDuration === null
                ? $stats->count
                : $storage->stats('request', $since, $filterSubtype, null, $until, $userId, minDuration: $minDuration)->count;
        }

        $durationFilterCounts = [];

        foreach ($durationBars as $filterKey => $bar) {
            $durationFilterCounts[$filterKey] = match (true) {
                $filterKey !== 'all' && $bar === null => 0,
                $subtype === null && $bar === null => $stats->count,
                default => $storage->stats('request', $since, $subtype, null, $until, $userId, minDuration: $bar)->count,
            };
        }

        // Both filters apply together, so the actually-shown total/pagination
        // is exactly this filter combination's own badge count above.
        $totalEntries = $durationFilterCounts[$durationFilter];
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        // This user's own per-route breakdown, ranked two different ways —
        // most-hit and slowest — same routeStats() the Requests tab's own
        // route list reads, just scoped to this one user via $userId.
        $routes = $storage->routeStats('request', $since, $until, $userId);

        $topRoutes = $routes->sortByDesc('count')->take(self::MAX_ROUTES_SHOWN)->map($this->presentRoute(...))->values();

        $slowestRoutes = $routes->filter(fn ($route) => $route->p95_duration !== null)
            ->sortByDesc('p95_duration')
            ->take(self::MAX_ROUTES_SHOWN)
            ->map($this->presentRoute(...))
            ->values();

        return [
            'userId' => $userId,
            'name' => $this->resolveNames([$userId])[$userId] ?? $userId,
            'lastSeen' => $this->userStorage()->lastSeenForUser($userId),
            'stats' => $stats,
            'okRequests' => ($bySubtype->get(HttpStatusGroup::Successful->value)?->count ?? 0) + ($bySubtype->get(HttpStatusGroup::Redirection->value)?->count ?? 0),
            'clientErrors' => $bySubtype->get(HttpStatusGroup::ClientError->value)?->count ?? 0,
            'serverErrors' => $bySubtype->get(HttpStatusGroup::ServerError->value)?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::ClientError->value, null, $until, $userId),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::ServerError->value, null, $until, $userId),
            'duration' => $duration,
            'entries' => $this->timelineStorage()->recent('request', $since, self::PER_PAGE, $subtype, null, $until, ($page - 1) * self::PER_PAGE, $minDuration, $userId),
            'totalEntries' => $totalEntries,
            'statusFilter' => $statusFilter,
            'statusFilterCounts' => $statusFilterCounts,
            'durationFilter' => $durationFilter,
            'durationFilterCounts' => $durationFilterCounts,
            'topRoutes' => $topRoutes,
            'slowestRoutes' => $slowestRoutes,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => $threshold,
        ];
    }

    /**
     * Adds display-ready method/path fields, same as
     * Livewire\Requests::presentRoute() — a plain "METHOD URI" split for an
     * ordinary route, or the capped method list for the merged Unmatched
     * Route row.
     */
    protected function presentRoute(object $route): object
    {
        if ($route->methods !== null) {
            return (object) [
                ...(array) $route,
                'method' => count($route->methods) > Requests::MAX_UNMATCHED_METHODS_SHOWN
                    ? 'ANY'
                    : implode('/', $route->methods),
                'path' => __('monitor::messages.common.unmatched_route'),
            ];
        }

        return (object) [
            ...(array) $route,
            'method' => Str::before($route->key, ' '),
            'path' => Str::after($route->key, ' '),
        ];
    }
}
