<?php

namespace LaravelMonitor\Storage;

use LaravelMonitor\Contracts\HashResolver;
use LaravelMonitor\Recorders\Requests;
use LaravelMonitor\Storage\Concerns\BuildsQueries;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\RecordType;

class DatabaseHashResolver implements HashResolver
{
    use BuildsQueries;

    public function resolveKeyHash(string $type, string $hash): ?string
    {
        // The merged Unmatched Route row (see DatabaseAggregateStorage::
        // routeStats()) links to a hash of the bare Requests::UNMATCHED_ROUTE
        // sentinel, which never exists as a literal stored key — resolve it
        // directly instead of scanning for a match that will never be found.
        if ($type === RecordType::Request->value && KeyHash::for(Requests::UNMATCHED_ROUTE) === $hash) {
            return Requests::UNMATCHED_ROUTE;
        }

        return $this->table()
            ->where('type', $type)
            ->select('key')
            ->distinct()
            ->pluck('key')
            ->first(fn (?string $key) => $key !== null && KeyHash::for($key) === $hash);
    }

    public function resolveUserIdHash(string $hash): ?string
    {
        return $this->table()
            ->whereNotNull('user_id')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->first(fn (string $userId) => KeyHash::for($userId) === $hash);
    }
}
