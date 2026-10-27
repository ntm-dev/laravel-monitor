<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Livewire\Concerns\FiltersByDuration;
use LaravelMonitor\Support\HttpStatusGroup;

class RequestDetail extends Card
{
    use CombinesSubtypeStats;
    use FiltersByDuration;

    public const PER_PAGE = 25;

    /** Status filter tabs; 'ok' covers 1xx/2xx/3xx. */
    public const STATUS_FILTERS = ['all', 'ok', '4xx', '5xx'];

    /**
     * Duration filter tabs: keep entries at or above the period's avg/p95 or
     * monitor.thresholds.request, same as Requests::DURATION_FILTERS.
     */
    public const DURATION_FILTERS = ['all', 'avg', 'p95', 'threshold'];

    public string $key = '';

    public string $statusFilter = 'all';

    public int $page = 1;

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    /**
     * A real method, not $set(), so tab buttons can target it with wire:loading.
     */
    public function setStatusFilter(string $value): void
    {
        $this->statusFilter = in_array($value, self::STATUS_FILTERS, true) ? $value : 'all';
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
        return 'monitor::livewire.request-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();
        $key = $this->key;
        $threshold = (int) config('monitor.thresholds.request', 1000);

        $ok2xx = $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::Successful->value, $key, $until);
        $ok3xx = $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::Redirection->value, $key, $until);

        // One grouped query instead of a stats() call per status group.
        $bySubtype = $storage->statsBySubtype('request', $since, $until, key: $key);
        $stats = $this->combineStats($bySubtype);

        $duration = $storage->durationStats('request', $since, $buckets, $key, null, $until);

        $statusFilter = in_array($this->statusFilter, self::STATUS_FILTERS, true) ? $this->statusFilter : 'all';
        $durationFilter = in_array($this->durationFilter, self::DURATION_FILTERS, true) ? $this->durationFilter : 'all';

        $statusSubtypes = [
            'all' => null,
            'ok' => [HttpStatusGroup::Informational->value, HttpStatusGroup::Successful->value, HttpStatusGroup::Redirection->value],
            '4xx' => HttpStatusGroup::ClientError->value,
            '5xx' => HttpStatusGroup::ServerError->value,
        ];

        // null means "no bar" for 'all', but "no duration data" for avg/p95.
        $durationBars = ['all' => null, 'avg' => $duration->avg, 'p95' => $duration->p95, 'threshold' => (float) $threshold];

        $subtype = $statusSubtypes[$statusFilter];
        $minDuration = $durationBars[$durationFilter];

        // Each tab group's badges count within the other group's active tab.
        $statusFilterCounts = [];

        foreach ($statusSubtypes as $filterKey => $filterSubtype) {
            $statusFilterCounts[$filterKey] = $filterSubtype === null && $minDuration === null
                ? $stats->count
                : $storage->stats('request', $since, $filterSubtype, $key, $until, minDuration: $minDuration)->count;
        }

        $durationFilterCounts = [];

        foreach ($durationBars as $filterKey => $bar) {
            $durationFilterCounts[$filterKey] = match (true) {
                $filterKey !== 'all' && $bar === null => 0,
                $subtype === null && $bar === null => $stats->count,
                default => $storage->stats('request', $since, $subtype, $key, $until, minDuration: $bar)->count,
            };
        }

        // Both filters apply together, so this badge count is the shown total.
        $totalEntries = $durationFilterCounts[$durationFilter];
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'stats' => $stats,
            'okRequests' => ($bySubtype->get(HttpStatusGroup::Successful->value)?->count ?? 0) + ($bySubtype->get(HttpStatusGroup::Redirection->value)?->count ?? 0),
            'clientErrors' => $bySubtype->get(HttpStatusGroup::ClientError->value)?->count ?? 0,
            'serverErrors' => $bySubtype->get(HttpStatusGroup::ServerError->value)?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::ClientError->value, $key, $until),
            'serverErrorBuckets' => $storage->countsPerBucket('request', $since, $buckets, HttpStatusGroup::ServerError->value, $key, $until),
            'duration' => $duration,
            'entries' => $this->timelineStorage()->recent('request', $since, self::PER_PAGE, $subtype, $key, $until, ($page - 1) * self::PER_PAGE, $minDuration),
            'totalEntries' => $totalEntries,
            'statusFilter' => $statusFilter,
            'statusFilterCounts' => $statusFilterCounts,
            'durationFilter' => $durationFilter,
            'durationFilterCounts' => $durationFilterCounts,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => $threshold,
        ];
    }
}
