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
 * "Queued Job" placeholder — mirrors Nightwatch's own collapsible per-job
 * track, not a single merged/proportional timeline (see buildTracks()'s own
 * docs for why: a queue worker can pick a job up long after dispatch, far
 * outside the dispatching root's own — often sub-second — duration, so
 * there's no single time scale both could stay legible on at once).
 */
trait MergesJobTimelines
{
    /**
     * @return list<array{id: string, badge: string, label: string, attempt: ?int, start: float, duration: int, entries: array}>
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
            'attempt' => null,
            'start' => 0.0,
            'duration' => max(1, (int) ($root->duration ?? 0)),
            'entries' => Timeline::build($root, $rootChildren),
        ]];

        // Wall clock, not proportional — see this trait's own docblock.
        $rootStart = (float) CarbonImmutable::parse($root->created_at)->format('U.u');

        foreach (array_keys($resolvedJobIds) as $jobId) {
            foreach ($jobExecutions->get($jobId) as $index => $execution) {
                $tracks[] = $this->jobTrack($execution, $index, $rootStart);
            }
        }

        return $tracks;
    }

    /**
     * @return array{id: string, badge: string, label: string, attempt: int, start: float, duration: int, entries: array}
     */
    protected function jobTrack(object $execution, int $attemptIndex, float $rootStart): array
    {
        $outcome = $execution->outcome;
        $duration = $outcome->duration !== null ? (float) $outcome->duration : 0.0;

        // The outcome's own created_at is stamped when it finished (see
        // Recorders\Jobs) — its processing start is that minus its own
        // duration, the same math the job's own standalone timeline uses.
        $processingStartedAt = (float) CarbonImmutable::parse($outcome->created_at)->format('U.u') - $duration / 1000;
        $start = max(0.0, ($processingStartedAt - $rootStart) * 1000);

        return [
            'id' => "job-{$outcome->id}",
            'badge' => 'JOB',
            'label' => $outcome->key ?? 'Job',
            'attempt' => $attemptIndex + 1,
            'start' => $start,
            'duration' => max(1, (int) $duration),
            'entries' => Timeline::build($outcome, $execution->children),
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
     * around) *that* job instead of the root.
     */
    protected function defaultTrackId(array $tracks): string
    {
        $requestedJobId = request()->query('job');

        if ($requestedJobId !== null) {
            $candidate = "job-{$requestedJobId}";

            if (collect($tracks)->contains('id', $candidate)) {
                return $candidate;
            }
        }

        return $tracks[0]['id'];
    }
}
