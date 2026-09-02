<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Support\HttpStatusGroup;

class RequestDetail extends Card
{
    use CombinesSubtypeStats;

    public const PER_PAGE = 25;

    /**
     * Status filter tabs above the entries table: 'all' shows every entry;
     * 'ok' keeps the combined 1xx/2xx/3xx group, '4xx'/'5xx' keep just that
     * status group.
     */
    public const STATUS_FILTERS = ['all', 'ok', '4xx', '5xx'];

    /**
     * Duration filter tabs above the entries table: 'all' shows every
     * entry; the rest keep only entries whose own duration is at or above
     * the given bar — the period's overall avg/p95 for 'avg'/'p95', or the
     * configured monitor.thresholds.request for 'threshold'. Same bars the
     * route list's own tabs use, see Livewire\Requests::DURATION_FILTERS.
     */
    public const DURATION_FILTERS = ['all', 'avg', 'p95', 'threshold'];

    public string $key = '';

    public string $statusFilter = 'all';

    public string $durationFilter = 'all';

    public int $page = 1;

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
    }

    /**
     * Real methods (not wire:click="$set(...)") so each tab button can
     * target it precisely by call signature for its own wire:loading
     * spinner — $set()'s own call isn't reliably matchable that way.
     */
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

        // One query grouped by subtype instead of five separate stats()
        // calls (total + 2xx/3xx/4xx/5xx) — see Livewire/Overview.php.
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

        // null for 'all' means "no duration bar" (a real, intentional
        // filter value); avg/p95 can also come back null from durationStats()
        // when the period has no duration data at all, which the loop below
        // tells apart from 'all' since only 'all' is expected to be null.
        $durationBars = ['all' => null, 'avg' => $duration->avg, 'p95' => $duration->p95, 'threshold' => (float) $threshold];

        $subtype = $statusSubtypes[$statusFilter];
        $minDuration = $durationBars[$durationFilter];

        // Each tab group's badges are cross-filtered against the OTHER
        // group's currently selected tab — e.g. with durationFilter='p95'
        // active, the status badges show how many of those p95+ entries
        // fall into each status, not each status's independent total
        // (which would still show "100 4xx" even with zero of them >= p95).
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

        // Both filters apply together, so the actually-shown total/pagination
        // is exactly this filter combination's own badge count above.
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
