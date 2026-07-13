<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;

class Exceptions extends Card
{
    use ResolvesUserNames;

    public const PER_PAGE = 15;

    public const SORTABLE = ['last_seen', 'count', 'users'];

    public string $search = '';

    /** all | handled | unhandled */
    public string $status = 'all';

    public string $userId = '';

    public string $sortBy = 'last_seen';

    public string $sortDirection = 'desc';

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    public function updatedUserId(): void
    {
        $this->page = 1;
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['all', 'handled', 'unhandled'], true) ? $status : 'all';
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
        return 'monitor::livewire.exceptions';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();
        $buckets = self::CHART_BUCKETS;
        $userId = $this->userId !== '' ? (int) $this->userId : null;

        $groups = $storage->exceptionGroups($since, $until, $userId);

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $groups = $groups->filter(fn ($group) => str_contains(strtolower($group->class), $needle)
                || str_contains(strtolower((string) $group->message), $needle))->values();
        }

        if ($this->status === 'handled') {
            $groups = $groups->filter(fn ($group) => $group->handled > 0)->values();
        } elseif ($this->status === 'unhandled') {
            $groups = $groups->filter(fn ($group) => $group->unhandled > 0)->values();
        }

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'last_seen';
        $groups = $groups->sortBy($sortBy, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $total = $groups->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        $topUsers = $storage->topUsers('exception', $since, 100, $until);
        $names = $this->resolveNames($topUsers->pluck('user_id')->all());

        return [
            'total' => $storage->stats('exception', $since, null, null, $until, $userId)->count,
            'handledCount' => $storage->stats('exception', $since, 'handled', null, $until, $userId)->count,
            'unhandledCount' => $storage->stats('exception', $since, 'unhandled', null, $until, $userId)->count,
            'handledBuckets' => $storage->countsPerBucket('exception', $since, $buckets, 'handled', null, $until, $userId),
            'unhandledBuckets' => $storage->countsPerBucket('exception', $since, $buckets, 'unhandled', null, $until, $userId),
            'groups' => $groups->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalGroups' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
            'from' => ($page - 1) * self::PER_PAGE,
            'users' => $topUsers->map(fn ($user) => (object) [
                'id' => $user->user_id,
                'name' => $names[$user->user_id] ?? "User #{$user->user_id}",
            ]),
        ];
    }
}
