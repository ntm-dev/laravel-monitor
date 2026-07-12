<?php

namespace LaravelMonitor\Livewire\Concerns;

use Throwable;

trait ResolvesUserNames
{
    /**
     * @param  array<int, int|string>  $ids
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
