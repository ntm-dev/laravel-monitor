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
     * @return list<array{id: string, badge: string, label: string, start: float, duration: int, entries: array, attempts?: list<array{attempt: int, status: string, outcomeId: int, start: float, duration: int, entries: array}>, totalAttemptsDuration?: int}>
     */
    protected function buildTracks(object $root, Collection $children, string $rootBadge): array
    {
        $jobExecutions = $this->jobExecutionsFor($children, $root->created_at);

        // Every 'queued' placeholder whose job_id resolved to at least one
        // outcome becomes its own track below instead of staying in the
        // root's own — everything else (unresolved queued jobs, every other
        // event type) stays exactly where it already was.
        $resolvedJobIds = [];

        $rootChildren = $children->reject(function (object $row) use ($jobExecutions, &$resolvedJobIds) {
            if ($row->type !== 'job' || $row->subtype !== 'queued') {
                return false;
            }

            $jobId = $row->payload['job_id'] ?? null;

            if ($jobId === null || ! $jobExecutions->has($jobId)) {
                return false;
            }

            $resolvedJobIds[$jobId] = true;

            return true;
        });

        $tracks = [[
            'id' => 'root',
            'badge' => $rootBadge,
            'label' => $root->key ?? $rootBadge,
            'start' => 0.0,
            'duration' => max(1, (int) ($root->duration ?? 0)),
            'entries' => Timeline::build($root, $rootChildren),
        ]];

        // Wall clock, not proportional — see this trait's own docblock.
        $rootStart = (float) CarbonImmutable::parse($root->created_at)->format('U.u');

        foreach (array_keys($resolvedJobIds) as $jobId) {
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
     * @return array{id: string, badge: string, label: string, start: float, duration: int, entries: array, attempts: list<array{attempt: int, status: string, outcomeId: int, start: float, duration: int, entries: array}>, totalAttemptsDuration: int}
     */
    protected function jobTrack(Collection $executions, float $rootStart): array
    {
        $attempts = $executions->values()->map(function (object $execution, int $index) use ($rootStart) {
            $outcome = $execution->outcome;
            $duration = $outcome->duration !== null ? (float) $outcome->duration : 0.0;

            // The outcome's own created_at is stamped when it finished (see
            // Recorders\Jobs) — its processing start is that minus its own
            // duration, the same math the job's own standalone timeline uses.
            $processingStartedAt = (float) CarbonImmutable::parse($outcome->created_at)->format('U.u') - $duration / 1000;
            $start = max(0.0, ($processingStartedAt - $rootStart) * 1000);

            return [
                'attempt' => $index + 1,
                // 'processed'/'failed'/'released' — surfaced on this
                // attempt's own row/bar the same way an HTTP status colours
                // a request root (see View\Components\Requests\TimelineRow).
                'status' => $outcome->subtype,
                // The specific outcome row this attempt came from — lets
                // defaultTrackId() find this track from a `?job=<id>` that
                // names any one of its attempts, not just the first.
                'outcomeId' => $outcome->id,
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
     * Defaults to the root ('root') unless a `?job=<id>` query string names
     * one of this page's own job tracks — set by JobAttemptController when it
     * redirects a directly-visited, already-tracked job attempt here, so
     * landing on this page from that job's own link expands (and scales
     * around) *that* job instead of the root. `<id>` is a specific outcome's
     * own row id, not the track id — a retried job's track holds several
     * attempts (see jobTrack()), so this matches against every attempt's own
     * 'outcomeId' rather than assuming the linked attempt is the track's
     * first one.
     */
    protected function defaultTrackId(array $tracks): string
    {
        $requestedOutcomeId = request()->query('job');

        if ($requestedOutcomeId !== null) {
            $requestedOutcomeId = (int) $requestedOutcomeId;

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
