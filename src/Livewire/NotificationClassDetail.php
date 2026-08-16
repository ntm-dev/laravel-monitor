<?php

namespace LaravelMonitor\Livewire;

/**
 * Aggregate view for one notification class (all its sends across the
 * selected period) — analogous to JobDetail/QueryDetail. $key is the
 * notification's FQCN, unlike NotificationDetail where $key is one send's
 * own database id. Its "recent sends" list is where a specific occurrence
 * gets picked: each row links to the request/job/command/scheduled task
 * timeline that triggered it — both sides of a mail-channel notification
 * show up together there — falling back to NotificationDetail's own
 * standalone page only when no such correlation exists.
 */
class NotificationClassDetail extends Card
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
        return 'monitor::livewire.notification-class-detail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();
        $key = $this->key;

        $bySubtype = $storage->statsBySubtype('notification', $since, $until, key: $key);

        $channels = $bySubtype->map(fn ($stat, $channel) => (object) [
            'label' => in_array($channel, Notifications::KNOWN_CHANNELS, true) ? ucfirst($channel) : $channel,
            'dot' => Notifications::CHANNEL_COLORS[$channel] ?? Notifications::CUSTOM_CHANNEL_COLOR,
            'count' => $stat->count,
        ])->sortByDesc('count')->values();

        $stats = $storage->stats('notification', $since, null, $key, $until);
        $totalEntries = $stats->count;
        $lastPage = max(1, (int) ceil($totalEntries / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $entries = $storage->recent('notification', $since, self::PER_PAGE, null, $key, $until, ($page - 1) * self::PER_PAGE);

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
            'total' => $bySubtype->sum('count'),
            'channels' => $channels,
            'volumeBuckets' => $storage->countsPerBucket('notification', $since, $buckets, null, $key, $until),
            'duration' => $storage->durationStats('notification', $since, $buckets, $key, null, $until),
            'entries' => $entries,
            'totalEntries' => $totalEntries,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }
}
