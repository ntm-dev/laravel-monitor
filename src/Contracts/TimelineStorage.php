<?php

namespace LaravelMonitor\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Single-entry and correlation lookups: one row (or one root's timeline) by
 * id/request_id/correlation_id/job_id, the raw "recent entries" list every
 * detail/list page reads from, and the batched label/type lookups used to
 * render a list of such references without one query per row.
 */
interface TimelineStorage
{
    /**
     * Latest entries of a type, newest first. Each item exposes:
     * key, subtype, payload (array), duration, user_id, created_at (Carbon).
     * $subtype accepts an array to match any of several subtypes at once
     * (e.g. every "ok" status group for a status filter tab). $minDuration
     * keeps only entries whose own duration is at or above it (a duration
     * filter tab).
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
    ): Collection;

    /**
     * The root entry (type $rootType — 'request' or 'job') recorded with the
     * given correlation id, or null when unknown. Exposes the same fields as
     * recent() rows plus request_id and start_offset.
     */
    public function findByRequestId(string $requestId, string $rootType = 'request'): ?object;

    /**
     * A single entry by its own primary key, scoped to $type, or null when
     * unknown — for a detail page about one specific occurrence (e.g. one
     * notification send) rather than an aggregate across many. Same row
     * shape as recent().
     */
    public function findById(int $id, string $type): ?object;

    /**
     * The first entry of $type whose payload has `correlation_id` equal to
     * $correlationId, or null when none match — links a mail-channel
     * notification's entry to the `mail` entry its send produced (and back).
     * Scans only entries of $type within $since/$until, since a correlated
     * pair is always recorded moments apart.
     */
    public function findByCorrelationId(string $type, string $correlationId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object;

    /**
     * Every entry correlated to the given request/job attempt (excluding the
     * root entry itself, type $rootType), ordered by where it started on the
     * timeline. Same row shape as findByRequestId().
     */
    public function timelineFor(string $requestId, string $rootType = 'request'): Collection;

    /**
     * The 'queued' dispatch-time entry sharing the given job_id (the queue
     * driver's own id — see Recorders\Jobs), or null when none match within
     * the window — the reverse of jobExecutionsByJobId(): given a job
     * attempt's own outcome (which carries the same job_id in its payload),
     * find what dispatched it. That entry's own request_id/type (via
     * rootTypesFor()) identifies the request/command/scheduled task it came
     * from, if any.
     */
    public function findQueuedJobByJobId(string $jobId, DateTimeInterface $since, ?DateTimeInterface $until = null): ?object;

    /**
     * For each given job_id, every outcome entry (processed/failed/released
     * — more than one on a retry) recorded for it, each paired with its own
     * children (queries/mail/... it triggered while running) — the data
     * needed to splice a dispatched job's own execution into the timeline of
     * whatever request/command/scheduled task dispatched it, producing a
     * single merged trace view instead of a bare, dead-end
     * "queued" placeholder. Keyed by job_id; a job_id with no matching
     * outcome yet is simply absent. Each job_id's own outcomes are ordered
     * oldest-first — MergesJobTimelines::jobTrack() numbers "Attempt #N"
     * purely by position in this collection, so an unordered implementation
     * would risk mislabeling which retry is which.
     *
     * @param  string[]  $jobIds
     * @return Collection<string, Collection<int, object{outcome: object, children: Collection}>>
     */
    public function jobExecutionsByJobId(array $jobIds, DateTimeInterface $since, ?DateTimeInterface $until = null): Collection;

    /**
     * "METHOD /path" label for each of the given request ids, keyed by
     * request_id, in a single query — batches what would otherwise be one
     * findByRequestId() call per row (e.g. a Query Detail page's calls table).
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function requestLabels(array $requestIds): Collection;

    /**
     * Which root type ('request', 'job', or 'command') each of the given
     * correlation ids belongs to, keyed by request_id, in a single query —
     * batches what would otherwise be one findByRequestId() probe per row
     * (e.g. deciding whether a list row should link to the Request, Job
     * Attempt, or Command Run timeline).
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function rootTypesFor(array $requestIds): Collection;

    /**
     * The root entry's own key ("METHOD /path" for a request, the job class
     * name, or the artisan command string) for each of the given correlation
     * ids, keyed by request_id, in a single query — the generic counterpart
     * to requestLabels() that doesn't assume the root is a request. Pair
     * with rootTypesFor() to know which of the three it is before deciding
     * which detail-page route to link to.
     *
     * @param  string[]  $requestIds
     * @return Collection<string, string>
     */
    public function rootLabelsFor(array $requestIds): Collection;

    /**
     * Earliest occurrence (across all retained data, ignoring the range) of a
     * given key, or null when it has never been seen.
     */
    public function firstSeen(string $type, string $key): ?CarbonImmutable;
}
