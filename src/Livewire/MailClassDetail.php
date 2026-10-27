<?php

namespace LaravelMonitor\Livewire;

/**
 * Aggregate view for one mail group; $key is the notification/mailable FQCN
 * or, failing both, the subject (Recorders\Mail's $groupKey). Each send links
 * to the run that triggered it.
 */
class MailClassDetail extends Card
{
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
        return 'monitor::livewire.mail-class-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $timelineStorage = $this->timelineStorage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        $stats = $storage->stats('mail', $since, null, $key, $until);
        $totalEntries = $stats->count;
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $entries = $timelineStorage->recent('mail', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE);

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
            'total' => $stats->count,
            'volumeBuckets' => $storage->countsPerBucket('mail', $since, $buckets, null, $key, $until),
            'duration' => $storage->durationStats('mail', $since, $buckets, $key, null, $until),
            'entries' => $entries,
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }
}
