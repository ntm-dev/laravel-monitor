<?php

namespace LaravelMonitor\Livewire;

use Throwable;

class Users extends Card
{
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

    /**
     * @return array<int|string, string>
     */
    protected function resolveNames(array $ids): array
    {
        $model = config('auth.providers.users.model');

        if ($ids === [] || ! is_string($model) || ! class_exists($model)) {
            return [];
        }

        try {
            return $model::query()
                ->findMany($ids)
                ->mapWithKeys(fn ($user) => [
                    $user->getKey() => (string) ($user->name ?? $user->email ?? 'User #'.$user->getKey()),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
