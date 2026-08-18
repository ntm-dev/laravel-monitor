<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;

class Users extends Card
{
    use ResolvesUserNames;

    /**
     * True when rendered as one of several summary cards on the Overview
     * tab; false when this is the entire body of the standalone Users tab
     * (whose header already shows the icon/title, so the section shouldn't
     * repeat them).
     */
    public bool $embedded = false;

    protected function view(): string
    {
        return 'monitor::livewire.users';
    }

    protected function data(): array
    {
        $since = $this->since();
        $until = $this->until();
        $storage = $this->storage();

        $topUsers = $storage->topUsers('request', $since, $this->limit, $until);
        $impactedUsers = $storage->topUsers('exception', $since, $this->limit, $until);

        $names = $this->resolveNames(
            $topUsers->pluck('user_id')->merge($impactedUsers->pluck('user_id'))->unique()->all()
        );

        $withNames = fn ($rows) => $rows->map(function ($row) use ($names) {
            $row->name = $names[$row->user_id] ?? "User #{$row->user_id}";

            return $row;
        });

        return [
            'topUsers' => $withNames($topUsers),
            'impactedUsers' => $withNames($impactedUsers),
            'authenticatedUsers' => $storage->topUsers('request', $since, 1000, $until)->count(),
            'authEvents' => $storage->recent('auth', $since, $this->limit, null, null, $until),
        ];
    }
}
