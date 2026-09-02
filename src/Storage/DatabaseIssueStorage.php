<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\IssueStorage;
use LaravelMonitor\Storage\Concerns\BuildsQueries;
use LaravelMonitor\Support\Format;
use Ramsey\Uuid\Uuid;

class DatabaseIssueStorage implements IssueStorage
{
    use BuildsQueries;

    public function syncIssues(string $type, array $lastSeenByKey): void
    {
        if ($lastSeenByKey === []) {
            return;
        }

        $existing = $this->issuesTable()
            ->where('type', $type)
            ->whereIn('key', array_keys($lastSeenByKey))
            ->get(['key', 'status', 'resolved_at'])
            ->keyBy('key');

        $now = CarbonImmutable::now();

        foreach ($lastSeenByKey as $key => $lastSeen) {
            $lastSeenValue = CarbonImmutable::instance($lastSeen);
            $row = $existing->get($key);

            if ($row === null) {
                $this->issuesTable()->insert([
                    'type' => $type,
                    'key' => $key,
                    'uuid' => Uuid::uuid7()->toString(),
                    'status' => 'open',
                    'first_seen' => $this->preciseTimestamp($lastSeenValue),
                    'last_seen' => $this->preciseTimestamp($lastSeenValue),
                    'resolved_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $update = ['last_seen' => $this->preciseTimestamp($lastSeenValue), 'updated_at' => $now];

            // A resolved issue that keeps occurring reopens itself
            // automatically. An ignored one stays ignored until the user
            // manually reopens it; recurrence alone shouldn't override that.
            if ($row->status === 'resolved'
                && $row->resolved_at !== null
                && $lastSeenValue->greaterThan(CarbonImmutable::parse($row->resolved_at))) {
                $update['status'] = 'open';
                $update['resolved_at'] = null;
            }

            $this->issuesTable()->where('type', $type)->where('key', $key)->update($update);
        }
    }

    /**
     * @param  string[]  $currentKeys
     */
    public function deleteMissingIssues(string $type, array $currentKeys): int
    {
        $query = $this->issuesTable()->where('type', $type)->where('status', 'open');

        if ($currentKeys !== []) {
            $query->whereNotIn('key', $currentKeys);
        }

        return $query->delete();
    }

    /**
     * @param  string[]  $keys
     */
    public function issueStatuses(string $type, array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        return $this->issuesTable()
            ->where('type', $type)
            ->whereIn('key', $keys)
            ->get(['id', 'uuid', 'key', 'status', 'priority', 'first_seen'])
            ->keyBy('key')
            ->map(fn ($row) => (object) [
                'id' => (int) $row->id,
                'uuid' => $row->uuid,
                'status' => $row->status,
                'priority' => $row->priority,
                'first_seen' => CarbonImmutable::parse($row->first_seen),
            ]);
    }

    public function setIssueStatus(string $type, string $key, string $status): void
    {
        if (! in_array($status, ['open', 'resolved', 'ignored'], true)) {
            return;
        }

        $now = CarbonImmutable::now();
        $exists = $this->issuesTable()->where('type', $type)->where('key', $key)->exists();

        if (! $exists) {
            // An action performed on an issue syncIssues() hasn't recorded
            // yet (edge case) — insert a fresh row rather than no-op.
            $this->issuesTable()->insert([
                'type' => $type,
                'key' => $key,
                'uuid' => Uuid::uuid7()->toString(),
                'status' => $status,
                'first_seen' => $this->preciseTimestamp($now),
                'last_seen' => $this->preciseTimestamp($now),
                'resolved_at' => $status === 'resolved' ? $this->preciseTimestamp($now) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->issuesTable()->where('type', $type)->where('key', $key)->update([
            'status' => $status,
            'resolved_at' => $status === 'resolved' ? $this->preciseTimestamp($now) : null,
            'updated_at' => $now,
        ]);
    }

    public function openIssueCount(): int
    {
        return $this->issuesTable()->where('status', 'open')->count();
    }

    public function expireStaleIssues(): int
    {
        $orphaned = $this->issuesTable()
            ->where('status', 'open')
            ->get(['id', 'type', 'key'])
            ->filter(fn ($issue) => ! $this->table()->where('type', $issue->type)->where('key', $issue->key)->exists());

        if ($orphaned->isEmpty()) {
            return 0;
        }

        return $this->issuesTable()->whereIn('id', $orphaned->pluck('id'))->delete();
    }

    public function setIssuePriority(string $type, string $key, string $priority): void
    {
        if (! array_key_exists($priority, Format::PRIORITIES)) {
            return;
        }

        $now = CarbonImmutable::now();
        $exists = $this->issuesTable()->where('type', $type)->where('key', $key)->exists();

        if (! $exists) {
            $this->issuesTable()->insert([
                'type' => $type,
                'key' => $key,
                'uuid' => Uuid::uuid7()->toString(),
                'status' => 'open',
                'priority' => $priority,
                'first_seen' => $this->preciseTimestamp($now),
                'last_seen' => $this->preciseTimestamp($now),
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->issuesTable()->where('type', $type)->where('key', $key)->update([
            'priority' => $priority,
            'updated_at' => $now,
        ]);
    }

    public function findIssueByUuid(string $uuid): ?object
    {
        $row = $this->issuesTable()->where('uuid', $uuid)->first();

        if ($row === null) {
            return null;
        }

        return (object) [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'type' => $row->type,
            'key' => $row->key,
            'status' => $row->status,
            'priority' => $row->priority,
            'first_seen' => CarbonImmutable::parse($row->first_seen),
            'last_seen' => CarbonImmutable::parse($row->last_seen),
        ];
    }
}
