<?php

namespace LaravelMonitor\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Raw entry lists and lookups by id/request_id/correlation_id/job_id,
 * plus batched label/type lookups for rendering lists of references.
 */
interface TimelineStorage
{
    /**
     * Latest entries, newest first: key, subtype, payload (array), duration, user_id,
     * created_at (Carbon). $subtype may be an array (match any); $minDuration keeps entries
     * at or above it. $beforeId/$afterId are a keyset cursor: only entries older/newer than that id.
     */
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
    ): Collection;

    /**
     * The root entry of type $rootType (request/job/command/scheduled_task) for a request_id,
     * or null. For 'job', the outcome row, never its dispatch or processing marker.
     * Same fields as recent() plus request_id and start_offset.
     */
    public function findByRequestId(string $requestId, string $rootType = 'request'): ?object;

    /**
     * One entry by id, scoped to $type, or null. Same row shape as recent().
     */
    public function findById(int $id, string $type): ?object;

    /**
     * findByRequestId() for several roots in one query, keyed by request_id.
     *
     * @param  list<string>  $requestIds
     * @return Collection<string, object>
     */
    public function findManyByRequestId(array $requestIds, string $rootType = 'request'): Collection;

    /**
     * First entry of $type within $since/$until whose payload correlation_id matches, or null.
     * Links a mail notification to its `mail` entry (and back); pass a tight window.
     */
    public function findByCorrelationId(string $type, string $correlationId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object;

    /**
     * Every entry sharing the request_id except the root itself, ordered by start_offset.
     * For a 'job' root, jobs it dispatched are included. Same row shape as findByRequestId().
     */
    public function timelineFor(string $requestId, string $rootType = 'request'): Collection;

    /**
     * Entries of one type sharing the request_id, oldest first. Same row shape as timelineFor().
     */
    public function entriesOfTypeFor(string $requestId, string $type): Collection;

    /**
     * timelineFor() for several roots in one query, keyed by request_id.
     *
     * @param  list<string>  $requestIds
     * @return Collection<string, Collection<int, object>>
     */
    public function timelinesFor(array $requestIds, string $rootType = 'request'): Collection;

    /**
     * The 'queued' dispatch entry with this payload job_id (see Recorders\Jobs::jobId()) within
     * the window, or null. Reverse of jobExecutionsByJobId(): finds what dispatched an attempt.
     */
    public function findQueuedJobByJobId(string $jobId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object;

    /**
     * Per job_id, every outcome entry (processed/failed/released) paired with its children,
     * keyed by job_id; job_ids with no outcome yet are absent. Outcomes must be oldest-first:
     * MergesJobTimelines::jobTrack() numbers attempts by position.
     *
     * @param  string[]  $jobIds
     * @return Collection<string, Collection<int, object{outcome: object, children: Collection}>>
     */
    public function jobExecutionsByJobId(array $jobIds, DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

    /**
     * "METHOD /path" label of each request root, keyed by request_id.
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function requestLabels(array $requestIds): Collection;

    /**
     * Root type (request/job/command/scheduled_task) of each request_id, keyed by request_id.
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function rootTypesFor(array $requestIds): Collection;

    /**
     * Root entry's key (request "METHOD /path", job class, command, scheduled task) of each
     * request_id, keyed by request_id. Pair with rootTypesFor() to pick the link target.
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function rootLabelsFor(array $requestIds): Collection;

    /**
     * Earliest retained occurrence of a key (ignores any range), or null.
     */
    public function firstSeen(string $type, string $key): ?CarbonImmutable;
}
