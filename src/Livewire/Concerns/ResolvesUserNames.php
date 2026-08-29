<?php

namespace LaravelMonitor\Livewire\Concerns;

use Throwable;

trait ResolvesUserNames
{
    /**
     * Every id is guard-qualified ("{guard}:{identifier}", see
     * Monitor::currentUserId()/Recorders\Authentication) — two different
     * guards can hand out the same raw identifier to two different users,
     * so each guard's own provider/model must be resolved separately
     * rather than always querying `auth.providers.users.model`. An id with
     * no guard prefix (recorded before this distinction existed) is
     * treated as the app's default guard, best-effort.
     *
     * Always returns one entry per input id — a name when resolvable,
     * otherwise a generic "User #<id> (<guard>)" label — so callers never
     * need their own fallback.
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

        // Keyed by guard => [original id => bare identifier] — the original
        // id (not a reconstructed "{guard}:{id}") is what a caller doing
        // $names[$row->user_id] looks up, and a legacy unprefixed id has no
        // guard segment to reconstruct anyway.
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
