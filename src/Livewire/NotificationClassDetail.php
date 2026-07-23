<?php

namespace LaravelMonitor\Livewire;

/**
 * Aggregate view for one notification class (all its sends across the
 * selected period) — analogous to JobDetail/QueryDetail. $key is the
 * notification's FQCN, unlike NotificationDetail where $key is one send's
 * own database id. Its "recent sends" list is where a specific occurrence
 * gets picked: each row links to the request/job attempt timeline that
 * triggered it (mirrors Nightwatch — both sides of a mail-channel
 * notification show up together there), falling back to NotificationDetail's
 * own standalone page only when no such correlation exists.
 */
class NotificationClassDetail extends Card
{
    public string $key = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $key = null): void
    {
        parent::mount($period, $from, $to);

        $this->key = $key ?? (string) request('key', '');
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

        $entries = $storage->recent('notification', $since, 50, null, $key, $until);

        $rootTypes = $storage->rootTypesFor(
            $entries->pluck('request_id')->filter()->unique()->values()->all()
        );

        $entries = $entries->map(function ($entry) use ($rootTypes) {
            $entry->timeline_url = match ($rootTypes->get($entry->request_id)) {
                'request' => route('monitor.requests.show', $entry->request_id),
                'job' => route('monitor.jobs.attempts.show', $entry->request_id),
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
        ];
    }
}
