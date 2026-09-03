<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\CombinesSubtypeStats;
use LaravelMonitor\Support\HttpStatusGroup;

/**
 * Aggregate view for one outgoing-request destination host (all calls to it
 * across the selected period) — analogous to MailClassDetail/RequestDetail.
 * $key is the host (see Recorders\OutgoingRequests::key()). Each row in its
 * "individual requests" list links back to whichever request/job/command/
 * scheduled task made the call, the same rootTypesFor()/rootLabelsFor()
 * pattern QueryDetail/MailClassDetail already use.
 */
class OutgoingDomainDetail extends Card
{
    use CombinesSubtypeStats;

    public const PER_PAGE = 25;

    public string $key = '';

    public int $page = 1;

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
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
        return 'monitor::livewire.outgoing-domain-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $timelineStorage = $this->timelineStorage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        $ok2xx = $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::Successful->value, $key, $until);
        $ok3xx = $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::Redirection->value, $key, $until);
        $networkErrorBuckets = $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::NetworkError->value, $key, $until);

        $bySubtype = $storage->statsBySubtype('outgoing_request', $since, $until, key: $key);
        $stats = $this->combineStats($bySubtype);

        $totalEntries = $stats->count;
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $entries = $timelineStorage->recent('outgoing_request', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE);

        $requestIds = $entries->pluck('request_id')->filter()->unique()->values()->all();
        $rootTypes = $timelineStorage->rootTypesFor($requestIds);
        $rootLabels = $timelineStorage->rootLabelsFor($requestIds);

        $entries = $entries->map(function ($entry) use ($rootTypes, $rootLabels) {
            $entry->sourceType = $rootTypes->get($entry->request_id);
            $entry->sourceLabel = $entry->sourceType !== null ? $rootLabels->get($entry->request_id) : null;
            $entry->sourceUrl = match ($entry->sourceType) {
                'request' => route('monitor.requests.show', $entry->request_id),
                'job' => route('monitor.jobs.attempts.show', $entry->request_id),
                'command' => route('monitor.commands.runs.show', $entry->request_id),
                'scheduled_task' => route('monitor.schedule.runs.show', $entry->request_id),
                default => null,
            };

            return $entry;
        });

        return [
            'stats' => $stats,
            'okRequests' => ($bySubtype->get(HttpStatusGroup::Successful->value)?->count ?? 0) + ($bySubtype->get(HttpStatusGroup::Redirection->value)?->count ?? 0),
            'clientErrors' => $bySubtype->get(HttpStatusGroup::ClientError->value)?->count ?? 0,
            'serverErrors' => $bySubtype->get(HttpStatusGroup::ServerError->value)?->count ?? 0,
            'networkErrors' => $bySubtype->get(HttpStatusGroup::NetworkError->value)?->count ?? 0,
            'okBuckets' => array_map(fn ($a, $b) => $a + $b, $ok2xx, $ok3xx),
            'clientErrorBuckets' => $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::ClientError->value, $key, $until),
            'serverErrorBuckets' => $storage->countsPerBucket('outgoing_request', $since, $buckets, HttpStatusGroup::ServerError->value, $key, $until),
            'networkErrorBuckets' => $networkErrorBuckets,
            'duration' => $storage->durationStats('outgoing_request', $since, $buckets, $key, null, $until),
            'entries' => $entries,
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'threshold' => (int) config('monitor.thresholds.outgoing_request', 1000),
        ];
    }
}
