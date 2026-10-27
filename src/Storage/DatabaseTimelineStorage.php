<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Recorders\Jobs;
use LaravelMonitor\Storage\Concerns\BuildsQueries;
use LaravelMonitor\Support\RecordType;

class DatabaseTimelineStorage implements TimelineStorage
{
    use BuildsQueries;

    public function recent(
        string $type,
        DateTimeInterface $since,
        int $limit = 50,
        string|array|null $subtype = null,
        ?string $key = null,
        ?DateTimeInterface $until = null,
        int $offset = 0,
        ?float $minDuration = null,
        int|string|null $userId = null,
        ?int $beforeId = null,
        ?int $afterId = null,
    ): Collection {
        return $this->query($type, $since, $subtype, $key, $until, $userId, $minDuration)
            ->when($beforeId !== null, fn (Builder $query) => $query->where('id', '<', $beforeId))
            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map($this->hydrate(...));
    }

    public function findByRequestId(string $requestId, string $rootType = 'request'): ?object
    {
        $row = $this->rootQuery([$requestId], $rootType)->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findManyByRequestId(array $requestIds, string $rootType = 'request'): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        return $this->rootQuery($requestIds, $rootType)
            ->get()
            ->map($this->hydrate(...))
            ->keyBy(fn (object $row) => $row->request_id);
    }

    /**
     * @param  list<string>  $requestIds
     */
    protected function rootQuery(array $requestIds, string $rootType): Builder
    {
        return $this->table()
            ->where('type', $rootType)
            // A job root is its outcome, not a dispatch it made or its 'processing'
            // marker, which share its request_id and type.
            ->when(
                $rootType === RecordType::Job->value,
                fn (Builder $query) => $query->whereNotIn('subtype', [Jobs::DISPATCH, Jobs::PROCESSING]),
            )
            ->whereIn('request_id', $requestIds);
    }

    public function findById(int $id, string $type): ?object
    {
        $row = $this->table()
            ->where('type', $type)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findByCorrelationId(string $type, string $correlationId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object
    {
        // Narrow by [type, created_at] before the JSON lookup; callers pass a tight window.
        $row = $this->table()
            ->where('type', $type)
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->where('payload->correlation_id', $correlationId)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function timelineFor(string $requestId, string $rootType = 'request'): Collection
    {
        return $this->timelineQuery([$requestId], $rootType)
            ->get()
            ->map($this->hydrate(...));
    }

    public function entriesOfTypeFor(string $requestId, string $type): Collection
    {
        return $this->table()
            ->where('request_id', $requestId)
            ->where('type', $type)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map($this->hydrate(...));
    }

    public function timelinesFor(array $requestIds, string $rootType = 'request'): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        return $this->timelineQuery($requestIds, $rootType)
            ->get()
            ->map($this->hydrate(...))
            ->groupBy(fn (object $row) => $row->request_id);
    }

    /**
     * @param  list<string>  $requestIds
     */
    protected function timelineQuery(array $requestIds, string $rootType): Builder
    {
        return $this->table()
            ->whereIn('request_id', $requestIds)
            // For a job root, keep the jobs it dispatched (same type, DISPATCH subtype);
            // MergesJobTimelines builds their tracks from these rows.
            ->when(
                $rootType === RecordType::Job->value,
                fn (Builder $query) => $query->where(fn (Builder $q) => $q
                    ->where('type', '!=', RecordType::Job->value)
                    ->orWhere('subtype', Jobs::DISPATCH)),
                fn (Builder $query) => $query->where('type', '!=', $rootType),
            )
            ->orderBy('start_offset')
            ->orderBy('id');
    }

    public function findQueuedJobByJobId(string $jobId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object
    {
        // Narrow by type/created_at before the JSON lookup, as in findByCorrelationId().
        $row = $this->table()
            ->where('type', 'job')
            ->where('subtype', 'queued')
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->where('payload->job_id', $jobId)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function jobExecutionsByJobId(array $jobIds, DateTimeInterface $since, ?DateTimeInterface $until = null): Collection
    {
        if ($jobIds === []) {
            return collect();
        }

        // Oldest first: MergesJobTimelines::jobTrack() numbers attempts by position.
        $outcomes = $this->table()
            ->where('type', 'job')
            // Outcomes only, as in rootQuery().
            ->whereNotIn('subtype', [Jobs::DISPATCH, Jobs::PROCESSING])
            ->whereIn('payload->job_id', $jobIds)
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map($this->hydrate(...));

        $children = $this->timelinesFor($outcomes->pluck('request_id')->all(), 'job');

        return $outcomes
            ->groupBy(fn (object $row) => $row->payload['job_id'] ?? '')
            ->map(fn (Collection $rows) => $rows->map(fn (object $outcome) => (object) [
                'outcome' => $outcome,
                'children' => $children->get($outcome->request_id, collect()),
            ]));
    }

    public function requestLabels(array $requestIds): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        // A request's key is already "METHOD /path" (see Recorders\Requests::record()).
        return $this->table()
            ->where('type', 'request')
            ->whereIn('request_id', $requestIds)
            ->pluck('key', 'request_id');
    }

    public function rootTypesFor(array $requestIds): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        return $this->table()
            ->whereIn('type', ['request', 'job', 'command', 'scheduled_task'])
            ->whereIn('request_id', $requestIds)
            ->pluck('type', 'request_id');
    }

    public function rootLabelsFor(array $requestIds): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        return $this->table()
            ->whereIn('type', ['request', 'job', 'command', 'scheduled_task'])
            ->whereIn('request_id', $requestIds)
            ->pluck('key', 'request_id');
    }

    public function firstSeen(string $type, string $key): ?CarbonImmutable
    {
        $first = $this->table()
            ->where('type', $type)
            ->where('key', $key)
            ->min('created_at');

        return $first !== null ? CarbonImmutable::parse($first) : null;
    }
}
