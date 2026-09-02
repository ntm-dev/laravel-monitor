<?php

namespace LaravelMonitor\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Storage\Concerns\BuildsQueries;

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
    ): Collection {
        return $this->query($type, $since, $subtype, $key, $until, $userId, $minDuration)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map($this->hydrate(...));
    }

    public function findByRequestId(string $requestId, string $rootType = 'request'): ?object
    {
        $row = $this->table()
            ->where('type', $rootType)
            ->where('request_id', $requestId)
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
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
        // Narrowed by the existing [type, created_at] index before the JSON
        // lookup — a correlated pair always lands moments apart, so callers
        // pass a tight since/until around the source entry rather than the
        // dashboard's full selected range.
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
        return $this->table()
            ->where('request_id', $requestId)
            ->where('type', '!=', $rootType)
            ->orderBy('start_offset')
            ->orderBy('id')
            ->get()
            ->map($this->hydrate(...));
    }

    public function findQueuedJobByJobId(string $jobId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object
    {
        // Narrowed by the existing [type, created_at] index before the JSON
        // lookup — same reasoning as findByCorrelationId().
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

        // Every outcome (processed/failed/released — more than one on a
        // retry) recorded for these job_ids, grouped back under the id they
        // share with their own 'queued' dispatch-time placeholder — the
        // caller already has that placeholder from its own timelineFor()
        // call, and stitches these children onto it (see Support\Timeline).
        // Ordered oldest-first: jobTrack() numbers attempts by this
        // collection's own array position (index + 1), so an unordered
        // result here — left to whatever the DB engine/index happens to
        // return — can hand a later retry a lower attempt number than an
        // earlier one, showing e.g. "Attempt #3" starting before "Attempt #2".
        $outcomes = $this->table()
            ->where('type', 'job')
            ->where('subtype', '!=', 'queued')
            ->whereIn('payload->job_id', $jobIds)
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map($this->hydrate(...));

        return $outcomes
            ->groupBy(fn (object $row) => $row->payload['job_id'] ?? '')
            ->map(fn (Collection $rows) => $rows->map(fn (object $outcome) => (object) [
                'outcome' => $outcome,
                'children' => $this->timelineFor($outcome->request_id, 'job'),
            ]));
    }

    public function requestLabels(array $requestIds): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        // The `request` entry's key is already stored as "METHOD /path"
        // (see Recorders\Requests) — no need to decode payload for this.
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
