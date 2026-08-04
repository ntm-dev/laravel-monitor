<?php

namespace LaravelMonitor\Http\Controllers\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use LaravelMonitor\Support\Timeline;

/**
 * Shared by RequestDetailController/JobAttemptController/CommandRunController/
 * ScheduleRunController: splits a root's own timeline into one "track" per
 * root/dispatched-job, instead of leaving a dispatched job a dead-end
 * "Queued Job" placeholder — each becomes its own collapsible per-job
 * track, not a single merged/proportional timeline (see buildTracks()'s own
 * docs for why: a queue worker can pick a job up long after dispatch, far
 * outside the dispatching root's own — often sub-second — duration, so
 * there's no single time scale both could stay legible on at once).
 */
trait MergesJobTimelines
{
    /**
     * @return list<array{id: string, badge: string, label: string, start: float, duration: int, entries: array, attempts?: list<array{attempt: int, status: string, outcomeId: string, start: float, duration: int, entries: array}>, totalAttemptsDuration?: int}>
     */
    protected function buildTracks(object $root, Collection $children, string $rootBadge): array
    {
        // Wall clock, not proportional — see this trait's own docblock.
        // Computed up front, not just for this track's own offsets below:
        // it's also the correct lower bound for finding a dispatched job's
        // own outcomes — $root->created_at is stamped at the request's own
        // END (see startedAt()'s own docblock), so a worker that finishes a
        // job before the dispatching request itself completes (common — see
        // jobTrack()'s docblock) would otherwise fall outside a window
        // floored at that later timestamp and never resolve to a track.
        $rootStart = $this->startedAt($root);

        $jobExecutions = $this->jobExecutionsFor($children, CarbonImmutable::createFromFormat('U.u', number_format($rootStart, 6, '.', '')));

        // Every 'queued' placeholder whose job_id resolved to at least one
        // outcome ALSO gets its own track below (see the foreach further
        // down) — but, unlike an earlier version of this method, its own
        // "JOB DISPATCH" row stays right where it already was among the
        // root's own children instead of being removed. Dropping it hid the
        // only marker of *when* (and in which phase) the root actually
        // called dispatch(); the separate track below only shows what
        // happened to the job afterwards (queue wait + however long it took
        // to run, often well outside the dispatching root's own duration),
        // which isn't a substitute for that.
        $resolvedJobIds = $children
            ->filter(fn (object $row) => $row->type === 'job' && $row->subtype === 'queued')
            ->map(fn (object $row) => $row->payload['job_id'] ?? null)
            ->filter(fn (?string $jobId) => $jobId !== null && $jobExecutions->has($jobId))
            ->unique();

        $tracks = [[
            'id' => 'root',
            'badge' => $rootBadge,
            'label' => $root->key ?? $rootBadge,
            'start' => 0.0,
            'duration' => max(1, (int) ($root->duration ?? 0)),
            'entries' => Timeline::build($root, $children),
        ]];

        foreach ($resolvedJobIds as $jobId) {
            $tracks[] = $this->jobTrack($jobExecutions->get($jobId), $rootStart);
        }

        return $tracks;
    }

    /**
     * One track per *dispatched job* (job_id), not per execution — every
     * retry of the same dispatch (its 'attempts', ordered oldest first)
     * nests under the one track instead of each becoming its own top-level
     * "JOB" entry (see View\Components\Requests\Timeline, which turns each
     * of these into its own coloured 'attempt' row underneath this track's
     * single, deliberately neutral root — see TimelineRow).
     *
     * @param  Collection<int, object>  $executions
     * @return array{id: string, badge: string, label: string, start: float, duration: int, entries: array, attempts: list<array{attempt: int, status: string, outcomeId: string, start: float, duration: int, entries: array}>, totalAttemptsDuration: int}
     */
    protected function jobTrack(Collection $executions, float $rootStart): array
    {
        $attempts = $executions->values()->map(function (object $execution, int $index) use ($rootStart) {
            $outcome = $execution->outcome;
            $duration = $outcome->duration !== null ? (float) $outcome->duration : 0.0;

            $processingStartedAt = $this->startedAt($outcome);
            $start = max(0.0, ($processingStartedAt - $rootStart) * 1000);

            return [
                'attempt' => $index + 1,
                // 'processed'/'failed'/'released' — surfaced on this
                // attempt's own row/bar the same way an HTTP status colours
                // a request root (see View\Components\Requests\TimelineRow).
                'status' => $outcome->subtype,
                // The specific outcome row this attempt came from — lets
                // defaultTrackId() find this track from a `?job=<uuid>` that
                // names any one of its attempts, not just the first. Its
                // request_id (not the int primary key), matching the uuid
                // every other cross-page link in this package already uses.
                'outcomeId' => $outcome->request_id,
                'start' => $start,
                'duration' => max(1, (int) $duration),
                'entries' => Timeline::build($outcome, $execution->children),
            ];
        })->all();

        $first = $executions->first()->outcome;
        $earliestStart = min(array_column($attempts, 'start'));
        $latestEnd = max(array_map(static fn (array $a) => $a['start'] + $a['duration'], $attempts));

        return [
            'id' => "job-{$first->id}",
            'badge' => 'JOB',
            'label' => $first->key ?? 'Job',
            // Bounding box spanning every attempt (including the gap
            // between retries) — this track's own bar visually contains all
            // of its attempts' bars, same as any other parent/child pair on
            // the timeline.
            'start' => $earliestStart,
            'duration' => max(1, (int) ($latestEnd - $earliestStart)),
            // No entries of its own — every event belongs to one specific
            // attempt (see 'attempts' below), never to the track as a whole.
            'entries' => [],
            'attempts' => $attempts,
            // Sum of each attempt's own duration, deliberately different
            // from the bounding-box 'duration' above (which includes idle
            // time between retries) — this is "how long the job actually
            // ran for", shown on the track's own root row instead of that
            // wall-clock span.
            'totalAttemptsDuration' => max(1, (int) array_sum(array_column($attempts, 'duration'))),
        ];
    }

    /**
     * The real wall-clock moment an entry (root or job outcome) actually
     * started, as a Unix timestamp with microsecond precision. Prefers the
     * 'started_at' payload field recorded directly at that moment (see
     * Recorders\Requests/Jobs) over reconstructing it from created_at -
     * duration — created_at is stamped when the entry is recorded
     * (RequestHandled/JobProcessed et al.), i.e. at its own END, not its
     * start. The subtraction fallback below only exists for rows persisted
     * before 'started_at' existed.
     */
    protected function startedAt(object $entry): float
    {
        $startedAt = $entry->payload['started_at'] ?? null;

        if ($startedAt !== null) {
            return (float) $startedAt;
        }

        return (float) CarbonImmutable::parse($entry->created_at)->format('U.u') - (float) ($entry->duration ?? 0) / 1000;
    }

    protected function jobExecutionsFor(Collection $children, DateTimeInterface $since): Collection
    {
        $jobIds = $children
            ->filter(fn (object $row) => $row->type === 'job' && $row->subtype === 'queued')
            ->map(fn (object $row) => $row->payload['job_id'] ?? null)
            ->filter()
            ->values()
            ->all();

        if ($jobIds === []) {
            return collect();
        }

        return $this->storage->jobExecutionsByJobId($jobIds, $since);
    }

    /**
     * Which track starts expanded (and doubles as the fixed scale every bar
     * on the page is positioned against — see View\Components\Requests\Timeline).
     * Defaults to the root ('root') unless $requestedOutcomeId names one of
     * this page's own job tracks — the route's own trailing {jobId} segment
     * (see routes/web.php's unnamed twin of this page's own named route),
     * forwarded by the calling controller, set by JobAttemptController when
     * it redirects a directly-visited, already-tracked job attempt here, so
     * landing on this page from that job's own link expands (and
     * scales around) *that* job instead of the root. It's a specific
     * outcome's own request_id, not the track id — a retried job's track
     * holds several attempts (see jobTrack()), so this matches against every
     * attempt's own 'outcomeId' rather than assuming the linked attempt is
     * the track's first one.
     */
    protected function defaultTrackId(array $tracks, ?string $requestedOutcomeId = null): string
    {
        if ($requestedOutcomeId !== null) {
            $match = collect($tracks)->first(
                fn (array $track) => collect($track['attempts'] ?? [])->contains('outcomeId', $requestedOutcomeId)
            );

            if ($match !== null) {
                return $match['id'];
            }
        }

        return $tracks[0]['id'];
    }
}
