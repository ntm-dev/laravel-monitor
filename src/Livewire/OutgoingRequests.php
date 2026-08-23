<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Support\HttpStatusGroup;

class OutgoingRequests extends Card
{
    use CombinesSubtypeStats;

    public const PER_PAGE = 15;

    public const SORTABLE = ['key', 'count', 'success', 'client_errors', 'server_errors', 'network_errors', 'avg_duration', 'p95_duration'];

    public string $sortBy = 'count';

    public string $sortDirection = 'desc';

    public int $page = 1;

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
        return 'monitor::livewire.outgoing-requests';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();

        $ok2xx = $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::Successful->value, null, $until);
        $ok3xx = $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::Redirection->value, null, $until);
        $networkErrorBuckets = $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::NetworkError->value, null, $until);

        $domains = $storage->routeStats('outgoing_request', $since, $until);

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'count';
        $domains = $domains->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $domains->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        // One query grouped by subtype instead of separate stats() calls — see Requests.php.
        $bySubtype = $storage->statsBySubtype('outgoing_request', $since, $until);

        return [
            'requests' => $this->combineStats($bySubtype),
            'okRequests' => ($bySubtype->get(HttpStatusGroup::Successful->value)?->count ?? 0) + ($bySubtype->get(HttpStatusGroup::Redirection->value)?->count ?? 0),
            'clientErrors' => $bySubtype->get(HttpStatusGroup::ClientError->value)?->count ?? 0,
            'serverErrors' => $bySubtype->get(HttpStatusGroup::ServerError->value)?->count ?? 0,
            'networkErrors' => $bySubtype->get(HttpStatusGroup::NetworkError->value)?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::ClientError->value, null, $until),
            'serverErrorBuckets' => $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::ServerError->value, null, $until),
            'networkErrorBuckets' => $networkErrorBuckets,
            'duration' => $storage->durationStats('outgoing_request', $since, $buckets, null, null, $until),
            'domains' => $domains->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalDomains' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => (int) config('monitor.thresholds.outgoing_request', 1000),
        ];
    }
}
