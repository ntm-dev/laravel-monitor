<?php

namespace LaravelMonitor\Livewire;

class MailAndNotifications extends Card
{
    public int $limit = 25;

    public string $search = '';

    protected function view(): string
    {
        return 'monitor::livewire.mail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = $this->chartBuckets();

        $bySubtype = $storage->statsBySubtype('mail', $since, $until);
        $direct = $bySubtype->get('direct')?->count ?? 0;
        $viaNotification = $bySubtype->get('notification')?->count ?? 0;

        // Grouped by mailable/notification class (Recorders\Mail's $groupKey)
        // instead of one row per send — click a row to see its individual
        // sends.
        $groups = $storage->keyStats('mail', $since, $until);

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $groups = $groups->filter(fn ($group) => str_contains(strtolower($group->key), $needle))->values();
        }

        $groups = $groups->sortByDesc('count')->values()->take($this->limit);

        return [
            // $direct + $viaNotification, not the list's total: mail
            // recorded before the direct/notification subtype existed has no
            // subtype at all, so it's invisible to statsBySubtype() — this
            // card's own legends legitimately undercount against the list
            // below until that older data ages out of the retention window.
            'direct' => $direct,
            'viaNotification' => $viaNotification,
            'directBuckets' => $storage->countsPerBucket('mail', $since, $buckets, 'direct', null, $until),
            'viaNotificationBuckets' => $storage->countsPerBucket('mail', $since, $buckets, 'notification', null, $until),
            'duration' => $storage->durationStats('mail', $since, $buckets, null, null, $until),
            'groups' => $groups,
        ];
    }
}
