<?php

namespace LaravelMonitor\Livewire;

class Notifications extends Card
{
    /**
     * Built-in Laravel channel keys. Anything else recorded as $event->channel
     * is a custom channel class's FQCN — charted and labelled as one combined
     * "Custom" series/row rather than by its (often long, app-specific) class
     * name, since the raw class name isn't meaningful chart/legend text and
     * an app can define arbitrarily many of them.
     */
    public const KNOWN_CHANNELS = ['mail', 'database', 'broadcast', 'vonage', 'nexmo', 'slack'];

    public const CHANNEL_COLORS = [
        'mail' => 'bg-blue-500',
        'database' => 'bg-neutral-400 dark:bg-neutral-500',
        'broadcast' => 'bg-purple-500',
        'vonage' => 'bg-amber-500',
        'nexmo' => 'bg-amber-500',
        'slack' => 'bg-emerald-500',
    ];

    public const CUSTOM_CHANNEL_COLOR = 'bg-rose-500';

    public int $limit = 25;

    public string $search = '';

    protected function view(): string
    {
        return 'monitor::livewire.notifications';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;

        $bySubtype = $storage->statsBySubtype('notification', $since, $until);
        $knownChannels = $bySubtype->keys()->intersect(self::KNOWN_CHANNELS)->values();
        $hasCustomChannels = $bySubtype->keys()->diff(self::KNOWN_CHANNELS)->isNotEmpty();

        $channelSeries = $knownChannels->map(fn ($channel) => [
            'label' => ucfirst($channel),
            'dot' => self::CHANNEL_COLORS[$channel] ?? 'bg-neutral-400 dark:bg-neutral-500',
            'data' => $storage->countsPerBucket('notification', $since, $buckets, $channel, null, $until),
        ])->values()->all();

        $customTotal = 0;

        if ($hasCustomChannels) {
            $customBuckets = array_fill(0, $buckets, 0);

            foreach ($bySubtype->keys()->diff(self::KNOWN_CHANNELS) as $channel) {
                $customTotal += $bySubtype->get($channel)?->count ?? 0;

                foreach ($storage->countsPerBucket('notification', $since, $buckets, $channel, null, $until) as $i => $count) {
                    $customBuckets[$i] += $count;
                }
            }

            $channelSeries[] = ['label' => 'Custom', 'dot' => self::CUSTOM_CHANNEL_COLOR, 'data' => $customBuckets];
        }

        $channels = $knownChannels->map(fn ($channel) => (object) [
            'label' => ucfirst($channel),
            'dot' => self::CHANNEL_COLORS[$channel] ?? 'bg-neutral-400 dark:bg-neutral-500',
            'count' => $bySubtype->get($channel)?->count ?? 0,
        ])->values();

        if ($hasCustomChannels) {
            $channels->push((object) ['label' => 'Custom', 'dot' => self::CUSTOM_CHANNEL_COLOR, 'count' => $customTotal]);
        }

        $groups = $storage->keyStats('notification', $since, $until);

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $groups = $groups->filter(fn ($group) => str_contains(strtolower($group->key), $needle))->values();
        }

        $groups = $groups->sortByDesc('count')->values()->take($this->limit);

        return [
            'total' => $bySubtype->sum('count'),
            'channels' => $channels->sortByDesc('count')->values(),
            'channelSeries' => $channelSeries,
            'duration' => $storage->durationStats('notification', $since, $buckets, null, null, $until),
            'groups' => $groups,
        ];
    }
}
