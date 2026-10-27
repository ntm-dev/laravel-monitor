<?php

namespace LaravelMonitor\Livewire;

class MailAndNotifications extends Card
{
    public const SORTABLE = ['key', 'count', 'avg_duration', 'p95_duration', 'last_seen'];

    public const PER_PAGE = 25;

    public string $search = '';

    public string $sortBy = 'last_seen';

    public string $sortDirection = 'desc';

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

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
        return 'monitor::livewire.mail';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->aggregateStorage();
        $buckets = $this->chartBuckets();

        $bySubtype = $storage->statsBySubtype('mail', $since, $until);
        $direct = $bySubtype->get('direct')?->count ?? 0;
        $viaNotification = $bySubtype->get('notification')?->count ?? 0;

        // One row per mailable/notification class (Recorders\Mail's $groupKey).
        $groups = $storage->keyStats('mail', $since, $until);

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $groups = $groups->filter(fn ($group) => str_contains(strtolower($group->key), $needle))->values();
        }

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'last_seen';
        $groups = $groups->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $groups->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            // Mail rows without a subtype aren't counted here, so these can
            // undercount against the list total.
            'direct' => $direct,
            'viaNotification' => $viaNotification,
            'directBuckets' => $storage->countsPerBucket('mail', $since, $buckets, 'direct', null, $until),
            'viaNotificationBuckets' => $storage->countsPerBucket('mail', $since, $buckets, 'notification', null, $until),
            'duration' => $storage->durationStats('mail', $since, $buckets, null, null, $until),
            'groups' => $groups->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalGroups' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }
}
