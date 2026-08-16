<?php

namespace LaravelMonitor\Livewire;

/**
 * Aggregate view for one mail class (all its sends across the selected
 * period) — analogous to NotificationClassDetail. $key is the mailable/
 * notification FQCN (or, for ad-hoc mail with neither, the subject — see
 * Recorders\Mail's $groupKey), unlike MailDetail where $key is one send's
 * own database id. Its "recent sends" list is where a specific occurrence
 * gets picked: each row links to the request/job attempt timeline that
 * triggered it, falling back to MailDetail's own standalone page only when
 * no such correlation exists.
 */
class MailClassDetail extends Card
{
    public const PER_PAGE = 50;

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
        $storage = $this->storage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        $stats = $storage->stats('mail', $since, null, $key, $until);
        $totalEntries = $stats->count;
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $entries = $storage->recent('mail', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE);

        $requestIds = $entries->pluck('request_id')->filter()->unique()->values()->all();
        $rootTypes = $storage->rootTypesFor($requestIds);
        $rootLabels = $storage->rootLabelsFor($requestIds);

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
