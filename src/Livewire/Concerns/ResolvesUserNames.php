<?php

namespace LaravelMonitor\Livewire\Concerns;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Throwable;

trait ResolvesUserNames
{
    /**
     * {id, name} options for x-monitor::user-filter: only users with entries
     * of this type in the window.
     *
     * @return Collection<int, object>
     */
    protected function userFilterOptions(string $type, DateTimeInterface $since, ?DateTimeInterface $until = null, int $limit = 100): Collection
    {
        $users = $this->userStorage()->topUsers($type, $since, $limit, $until);
        $names = $this->resolveNames($users->pluck('user_id')->all());

        return $users->map(fn ($user) => (object) [
            'id' => $user->user_id,
            'name' => $names[$user->user_id],
        ]);
    }

    /**
     * Resolve "{guard}:{identifier}" ids through each guard's own provider
     * model (unprefixed ids use the default guard). Returns one entry per id,
     * falling back to "User #<id> (<guard>)".
     *
     * @param  array<int, int|string>  $ids
     * @return array<int|string, string>
     */
    protected function resolveNames(array $ids): array
    {
        $ids = array_unique(array_filter($ids, fn ($id) => $id !== null && $id !== ''));

        if ($ids === []) {
            return [];
        }

        // guard => [original id => bare identifier]; callers look names up by the original id.
        $byGuard = [];
        foreach ($ids as $id) {
            [$guard, $identifier] = $this->splitGuardId((string) $id);
            $byGuard[$guard][$id] = $identifier;
        }

        $names = [];
        foreach ($byGuard as $guard => $map) {
            $resolved = $this->resolveGuardNames($guard, array_values($map));

            foreach ($map as $originalId => $identifier) {
                $names[$originalId] = $resolved[$identifier] ?? "User #{$identifier} ({$guard})";
            }
        }

        return $names;
    }

    /**
     * @return array<int, string>
     */
    private function splitGuardId(string $id): array
    {
        if (str_contains($id, ':')) {
            return explode(':', $id, 2);
        }

        return [(string) config('auth.defaults.guard'), $id];
    }

    /**
     * @param  string[]  $identifiers
     * @return array<string, string>
     */
    private function resolveGuardNames(string $guard, array $identifiers): array
    {
        $provider = config("auth.guards.{$guard}.provider");
        $model = $provider ? config("auth.providers.{$provider}.model") : null;

        if (! is_string($model) || ! class_exists($model)) {
            return [];
        }

        try {
            return $model::query()
                ->findMany($identifiers)
                ->mapWithKeys(fn ($user) => [(string) $user->getKey() => $user->name ?? $user->email ?? null])
                ->filter()
                ->map(fn ($name) => (string) $name)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
