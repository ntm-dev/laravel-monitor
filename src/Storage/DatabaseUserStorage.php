<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\UserStorage;
use LaravelMonitor\Storage\Concerns\BuildsQueries;
use LaravelMonitor\Support\HttpStatusGroup;
use LaravelMonitor\Support\RecordType;

class DatabaseUserStorage implements UserStorage
{
    use BuildsQueries;

    public function authenticatedUserCountsPerBucket(
        DateTimeInterface $since,
        int $buckets = 40,
        ?DateTimeInterface $until = null,
    ): array {
        [$start, $bucketSize] = $this->bucketGrid($since, $buckets, $until);

        $usersPerBucket = array_fill(0, $buckets, []);

        $this->query(RecordType::Request->value, $since, null, null, $until)
            ->whereNotNull('user_id')
            ->orderByDesc('created_at')
            ->limit($this->maxSampleRows())
            ->get(['created_at', 'user_id'])
            ->each(function ($row) use (&$usersPerBucket, $start, $bucketSize, $buckets): void {
                $usersPerBucket[$this->bucketIndex($row->created_at, $start, $bucketSize, $buckets)][$row->user_id] = true;
            });

        return array_map('count', $usersPerBucket);
    }

    public function requestAuthCountsPerBucket(
        DateTimeInterface $since,
        int $buckets = 40,
        ?DateTimeInterface $until = null,
    ): array {
        [$start, $bucketSize] = $this->bucketGrid($since, $buckets, $until);

        $authenticated = array_fill(0, $buckets, 0);
        $guest = array_fill(0, $buckets, 0);

        $this->query(RecordType::Request->value, $since, null, null, $until)
            ->orderByDesc('created_at')
            ->limit($this->maxSampleRows())
            ->get(['created_at', 'user_id'])
            ->each(function ($row) use (&$authenticated, &$guest, $start, $bucketSize, $buckets): void {
                $index = $this->bucketIndex($row->created_at, $start, $bucketSize, $buckets);

                if ($row->user_id !== null) {
                    $authenticated[$index]++;
                } else {
                    $guest[$index]++;
                }
            });

        return ['authenticated' => $authenticated, 'guest' => $guest];
    }

    public function topUsers(
        string $type,
        DateTimeInterface $since,
        int $limit = 10,
        ?DateTimeInterface $until = null,
    ): Collection {
        // See DatabaseCacheAndQueryStorage::cacheKeyStats() for why the
        // GROUP BY runs over a capped subquery, ordered by created_at rather
        // than id, rather than the raw filtered table directly.
        $sample = $this->query($type, $since, null, null, $until)
            ->whereNotNull('user_id')
            ->select('user_id')
            ->orderByDesc('created_at')
            ->limit($this->maxSampleRows());

        return $this->table()
            ->fromSub($sample, 't')
            ->select('user_id')
            ->selectRaw('count(*) as aggregate_count')
            ->groupBy('user_id')
            ->orderByDesc('aggregate_count')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $row->count = (int) $row->aggregate_count;
                unset($row->aggregate_count);

                return $row;
            });
    }

    public function userStats(DateTimeInterface $since, ?DateTimeInterface $until = null): Collection
    {
        $rows = $this->table()
            ->whereIn('type', [RecordType::Request->value, RecordType::Job->value, RecordType::Exception->value])
            ->whereNotNull('user_id')
            ->where(fn (Builder $q) => $q
                ->where('type', RecordType::Request->value)
                ->orWhere('type', RecordType::Exception->value)
                ->orWhere(fn (Builder $q2) => $q2->where('type', RecordType::Job->value)->where('subtype', 'queued')))
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            // Rows arrive newest-first, so the first row seen per user_id is
            // its last_seen.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->maxSampleRows())
            ->get(['type', 'subtype', 'user_id', 'created_at']);

        $groups = [];

        foreach ($rows as $row) {
            $group = &$groups[$row->user_id];
            $group ??= ['success' => 0, 'client_errors' => 0, 'server_errors' => 0, 'requests' => 0, 'queued_jobs' => 0, 'exceptions' => 0, 'last_seen' => $row->created_at];

            match ($row->type) {
                RecordType::Request->value => $this->tallyUserRequest($group, $row->subtype),
                RecordType::Job->value => $group['queued_jobs']++,
                RecordType::Exception->value => $group['exceptions']++,
                default => null,
            };
            unset($group);
        }

        return collect($groups)
            ->map(fn (array $group, string $userId) => (object) [
                'user_id' => $userId,
                'success' => $group['success'],
                'client_errors' => $group['client_errors'],
                'server_errors' => $group['server_errors'],
                'requests' => $group['requests'],
                'queued_jobs' => $group['queued_jobs'],
                'exceptions' => $group['exceptions'],
                'last_seen' => CarbonImmutable::parse($group['last_seen']),
            ])
            ->values();
    }

    /**
     * @param  array{success: int, client_errors: int, server_errors: int, requests: int, queued_jobs: int, exceptions: int, last_seen: mixed}  $group
     */
    protected function tallyUserRequest(array &$group, ?string $subtype): void
    {
        $group['requests']++;

        match ($subtype) {
            HttpStatusGroup::Informational->value, HttpStatusGroup::Successful->value, HttpStatusGroup::Redirection->value => $group['success']++,
            HttpStatusGroup::ClientError->value => $group['client_errors']++,
            HttpStatusGroup::ServerError->value => $group['server_errors']++,
            default => null,
        };
    }

    public function lastSeenForUser(string $userId): ?CarbonImmutable
    {
        $lastSeen = $this->table()
            ->where('user_id', $userId)
            ->max('created_at');

        return $lastSeen !== null ? CarbonImmutable::parse($lastSeen) : null;
    }
}
