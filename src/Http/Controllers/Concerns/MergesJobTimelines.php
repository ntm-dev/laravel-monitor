<?php

namespace LaravelMonitor\Http\Controllers\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use LaravelMonitor\Recorders\Jobs;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Timeline;

use function usort;

/**
 * Splits a root's timeline into one collapsible track for the root plus one per
 * dispatched job: a worker may run a job long after dispatch, so a single shared
 * scale wouldn't stay legible.
 */
trait MergesJobTimelines
{
    /**
     * @return list<array{id: string, badge: string, label: string, start: float, duration: float, entries: array, attempts?: list<array{attempt: int, status: string, outcomeId: string, start: float, duration: float, entries: array}>, totalAttemptsDuration?: float}>
     */
    protected function buildTracks(object $root, Collection $children, string $rootBadge): array
    {
        // Root start (not created_at, which marks its end) also floors the job
        // lookup: a worker can finish a job before the dispatching root ends.
        $rootStart = $this->startedAt($root);
        $since = CarbonImmutable::createFromFormat('U.u', number_format($rootStart, 6, '.', ''));

        $rootTrack = [
            'id' => 'root',
            'badge' => $rootBadge,
            'label' => $root->key ?? $rootBadge,
            'start' => 0.0,
            'duration' => max(0.0, (float) ($root->duration ?? 0)),
            'entries' => Timeline::build($root, $children, $rootStart),
        ];

        $jobTracks = $this->jobTracksBelow($children, $rootStart, $since);

        // Order by when each job ran; stable sort keeps ties in dispatch order.
        usort($jobTracks, static fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return [$rootTrack, ...$jobTracks];
    }

    /**
     * One track per job reachable through dispatches at any depth. Breadth-first,
     * one storage round trip per level; $seen guards against cycles.
     *
     * @param  Collection<int, object>  $children
     * @return list<array<string, mixed>>
     */
    protected function jobTracksBelow(Collection $children, float $rootStart, DateTimeInterface $since): array
    {
        $tracks = [];
        $seen = [];
        $frontier = $children;

        while ($frontier->isNotEmpty()) {
            $executions = $this->jobExecutionsFor($frontier, $since);

            $jobIds = $this->dispatchedJobIds($frontier)
                ->filter(fn (string $jobId) => $executions->has($jobId) && ! isset($seen[$jobId]));

            $next = collect();

            foreach ($jobIds as $jobId) {
                $seen[$jobId] = true;
                $attempts = $executions->get($jobId);
                $tracks[] = $this->jobTrack($attempts, $rootStart);

                foreach ($attempts as $attempt) {
                    $next = $next->concat($attempt->children);
                }
            }

            $frontier = $next;
        }

        return $tracks;
    }

    /**
     * job_ids of every job dispatched from this set of timeline rows.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, string>
     */
    protected function dispatchedJobIds(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (object $row) => $row->type === RecordType::Job->value && $row->subtype === Jobs::DISPATCH)
            ->map(fn (object $row) => $row->payload['job_id'] ?? null)
            ->filter(fn (?string $jobId) => $jobId !== null)
            ->unique()
            ->values();
    }

    /**
     * One track per dispatched job (job_id); its retries nest as 'attempts', oldest first.
     *
     * @param  Collection<int, object>  $executions
     * @return array{id: string, badge: string, label: string, start: float, duration: float, entries: array, attempts: list<array{attempt: int, status: string, outcomeId: string, start: float, duration: float, entries: array}>, totalAttemptsDuration: float}
     */
    protected function jobTrack(Collection $executions, float $rootStart): array
    {
        $attempts = $executions->values()->map(function (object $execution, int $index) use ($rootStart) {
            $outcome = $execution->outcome;
            $processingStartedAt = $this->startedAt($outcome);
            $entries = Timeline::build($outcome, $execution->children, $processingStartedAt);

            // The root entry's duration, already stretched to cover late children
            // (Timeline::rootDuration()), so the bounding box matches what renders.
            $duration = (float) ($entries[0]->duration ?? 0);

            // round(..., 3) strips float cancellation noise from subtracting two
            // epoch-scale timestamps; microsecond precision is all there is.
            $start = round(max(0.0, ($processingStartedAt - $rootStart) * 1000), 3);

            return [
                'attempt' => $index + 1,
                // 'processed'/'failed'/'released'.
                'status' => $outcome->subtype,
                // Outcome request_id, matched by defaultTrackId().
                'outcomeId' => $outcome->request_id,
                'start' => $start,
                'duration' => max(0.0, $duration),
                'entries' => $entries,
            ];
        })->all();

        $first = $executions->first()->outcome;
        $earliestStart = min(array_column($attempts, 'start'));
        $latestEnd = max(array_map(static fn (array $a) => $a['start'] + $a['duration'], $attempts));

        return [
            'id' => "job-{$first->id}",
            'badge' => 'JOB',
            'label' => $first->key ?? 'Job',
            // Bounding box over every attempt, gaps between retries included.
            'start' => $earliestStart,
            'duration' => max(0.0, $latestEnd - $earliestStart),
            // Events belong to attempts, never to the track itself.
            'entries' => [],
            'attempts' => $attempts,
            // Actual run time, excluding idle gaps between retries.
            'totalAttemptsDuration' => max(0.0, array_sum(array_column($attempts, 'duration'))),
        ];
    }

    /**
     * Unix timestamp (microseconds) an entry started: payload 'started_at', else
     * created_at - duration (created_at marks the end, at second precision).
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
        $jobIds = $this->dispatchedJobIds($children)->all();

        if ($jobIds === []) {
            return collect();
        }

        return $this->storage->jobExecutionsByJobId($jobIds, $since);
    }

    /**
     * Track that starts expanded (and sets the page's scale): the one holding the
     * attempt whose outcome request_id is $requestedOutcomeId (the route's trailing
     * {jobId}), else the root.
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
