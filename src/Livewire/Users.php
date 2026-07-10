<?php

namespace LaravelMonitor\Livewire;

use Throwable;

class Users extends Card
{
    public function render()
    {
        $since = $this->since();
        $storage = $this->storage();

        $topUsers = $storage->topUsers('request', $since, 8);
        $names = $this->resolveNames($topUsers->pluck('user_id')->all());

        return view('monitor::livewire.users', [
            'topUsers' => $topUsers->map(function ($row) use ($names) {
                $row->name = $names[$row->user_id] ?? "User #{$row->user_id}";

                return $row;
            }),
            'authEvents' => $storage->recent('auth', $since, 10),
        ]);
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
