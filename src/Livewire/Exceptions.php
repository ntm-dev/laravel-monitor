<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\Format;

class Exceptions extends Card
{
    use ResolvesUserNames;

    public const PER_PAGE = 15;

    public const SORTABLE = ['last_seen', 'count', 'users'];

    /** Status filter tabs shown above the table. */
    public const FILTERS = ['all' => 'View All', 'handled' => 'Handled', 'unhandled' => 'Unhandled'];

    /** Table columns: label, alignment and whether the header is sortable. */
    public const COLUMNS = [
        'last_seen' => ['label' => 'Last Seen', 'align' => 'left', 'sortable' => true],
        'status' => ['label' => 'Status', 'align' => 'left', 'sortable' => false],
        'class' => ['label' => 'Exception', 'align' => 'left', 'sortable' => false],
        'count' => ['label' => 'Count', 'align' => 'right', 'sortable' => true],
        'users' => ['label' => 'Users', 'align' => 'right', 'sortable' => true],
    ];

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
        $buckets = $this->chartBuckets();
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
        $tz = Format::timezone();

        // One query grouped by subtype instead of three separate stats()
        // calls (total + handled + unhandled) — see Livewire/Overview.php.
        $bySubtype = $storage->statsBySubtype('exception', $since, $until, $userId);

        return [
            'total' => $bySubtype->sum('count'),
            'handledCount' => $bySubtype->get('handled')?->count ?? 0,
            'unhandledCount' => $bySubtype->get('unhandled')?->count ?? 0,
            'handledBuckets' => $storage->countsPerBucket('exception', $since, $buckets, 'handled', null, $until, $userId),
            'unhandledBuckets' => $storage->countsPerBucket('exception', $since, $buckets, 'unhandled', null, $until, $userId),
            'filters' => self::FILTERS,
            'columns' => self::COLUMNS,
            'groups' => $groups->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)
                ->map(fn ($group) => $this->presentGroup($group, $tz))
                ->values(),
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

    /**
     * Shape a grouped-exception row into a display-ready object so the Blade
     * view carries no formatting logic.
     */
    protected function presentGroup(object $group, string $tz): object
    {
        return (object) [
            'key' => $group->key,
            'class' => $group->class,
            'class_short' => class_basename($group->class),
            'message' => $group->message,
            'file' => $group->file,
            'line' => $group->line,
            'count' => $group->count,
            'users' => $group->users,
            'unhandled' => $group->unhandled,
            'handled' => $group->unhandled === 0,
            'last_seen_human' => $group->last_seen?->diffForHumans(short: true),
            'last_seen_full' => $group->last_seen !== null ? Format::datetime($group->last_seen).' '.$tz : null,
        ];
    }
}
