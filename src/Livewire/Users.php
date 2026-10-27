<?php

namespace LaravelMonitor\Livewire;

use Carbon\CarbonImmutable;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Contracts\UserStorage;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;

class Users extends Card
{
    use ResolvesUserNames;

    public const PER_PAGE = 25;

    public const SORTABLE = ['name', 'success', 'client_errors', 'server_errors', 'requests', 'queued_jobs', 'exceptions', 'last_seen'];

    /**
     * True when rendered as a summary card on the Overview tab; charts and the
     * sortable user list are only built for the standalone Users tab.
     */
    public bool $embedded = false;

    public string $sortBy = 'requests';

    public string $sortDirection = 'desc';

    public int $page = 1;

    public int $limit = 5;

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
        return 'monitor::livewire.users';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->userStorage();

        if ($this->embedded) {
            return $this->embeddedData($storage, $this->timelineStorage(), $since, $until);
        }

        $buckets = $this->chartBuckets();
        $requestAuthBuckets = $storage->requestAuthCountsPerBucket($since, $buckets, $until);

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'requests';

        $userStats = $storage->userStats($since, $until);
        $names = $this->resolveNames($userStats->pluck('user_id')->all());

        $userStats = $userStats
            ->map(function ($user) use ($names) {
                $user->name = $names[$user->user_id];

                return $user;
            })
            ->sortBy(fn ($user) => $sortBy === 'last_seen' ? $user->last_seen->getTimestamp() : $user->{$sortBy}, SORT_REGULAR, $this->sortDirection === 'desc')
            ->values();

        $total = $userStats->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'authenticatedUsers' => $storage->topUsers('request', $since, null, $until)->count(),
            'authenticatedUserBuckets' => $storage->authenticatedUserCountsPerBucket($since, $buckets, $until),
            'authenticatedRequestBuckets' => $requestAuthBuckets['authenticated'],
            'guestRequestBuckets' => $requestAuthBuckets['guest'],
            'authenticatedRequests' => array_sum($requestAuthBuckets['authenticated']),
            'guestRequests' => array_sum($requestAuthBuckets['guest']),
            'users' => $userStats->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'totalUsers' => $total,
            'sortBy' => $sortBy,
            'page' => $page,
            'lastPage' => $lastPage,
            'perPage' => self::PER_PAGE,
        ];
    }

    /** The compact summary shown on the Overview tab. */
    protected function embeddedData(UserStorage $storage, TimelineStorage $timelineStorage, CarbonImmutable $since, ?CarbonImmutable $until): array
    {
        $topUsers = $storage->topUsers('request', $since, $this->limit, $until);
        $impactedUsers = $storage->topUsers('exception', $since, $this->limit, $until);

        $names = $this->resolveNames(
            $topUsers->pluck('user_id')->merge($impactedUsers->pluck('user_id'))->unique()->all()
        );

        $withNames = fn ($rows) => $rows->map(function ($row) use ($names) {
            $row->name = $names[$row->user_id];

            return $row;
        });

        return [
            'topUsers' => $withNames($topUsers),
            'impactedUsers' => $withNames($impactedUsers),
            'authenticatedUsers' => $storage->topUsers('request', $since, null, $until)->count(),
            'authEvents' => $timelineStorage->recent('auth', $since, $this->limit, null, null, $until),
        ];
    }
}
