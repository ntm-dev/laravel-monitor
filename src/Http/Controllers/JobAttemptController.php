<?php

namespace LaravelMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Http\Controllers\Concerns\MergesJobTimelines;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Sql;

/**
 * Renders the standalone Job Attempt Detail page: one queued job execution
 * and every event it triggered (queries, mail, notifications, cache,
 * outgoing requests), on the same waterfall timeline used for requests —
 * notification/mail rows link here (rather than to a standalone
 * notification/mail page) because both sides of a mail-channel
 * notification already show up side by side on this one timeline. Owns its
 * own route (`monitor.jobs.attempts.show`), same as RequestDetailController.
 *
 * When this job was itself dispatched from a tracked request/command/
 * scheduled task (or another job), this redirects to THAT root's own page
 * instead of rendering here — its own merged timeline (see
 * Http\Controllers\Concerns\MergesJobTimelines) already splices this job's
 * execution in, so this page would otherwise just be a strictly smaller,
 * duplicate view of the same data. Only a job with no resolvable dispatcher
 * (no job_id at all — the sync connection never gets one — or dispatched
 * outside any tracked context) renders its own standalone timeline here,
 * same as before this existed.
 */
class JobAttemptController
{
    use MergesJobTimelines;

    /**
     * Recorder type => events-summary bucket key. No 'job' entry — unlike a
     * request, whose children include jobs it queued, a job attempt's own
     * timeline shouldn't summarise itself.
     */
    protected const SUMMARY_TYPES = [
        'slow_query' => 'queries',
        'cache' => 'cache',
        'mail' => 'mail',
        'notification' => 'notifications',
        'outgoing_request' => 'outgoing',
        'lazy_loading' => 'lazy_loading',
    ];

    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $attemptId, ?string $jobId = null): View|RedirectResponse
    {
        $root = $this->storage->findByRequestId($attemptId, 'job');

        abort_unless($root !== null, 404);

        if (($ancestorUrl = $this->ancestorUrl($root)) !== null) {
            // A trailing <uuid> segment (not a ?job= query string, and no
            // "/job" literal in between — shorter, and doesn't leak an
            // otherwise-guessable query key) tells the landing page to
            // expand (and scale its whole timeline around) *this* job's own
            // track instead of its default root — see
            // MergesJobTimelines::defaultTrackId() and the matching unnamed
            // route registered right after each named show route in
            // routes/web.php. Its request_id (the uuid this very route was
            // reached with as $attemptId), not the int primary key, matching
            // the uuid every other cross-page link in this package already
            // uses.
            return redirect("{$ancestorUrl}/{$root->request_id}");
        }

        $children = $this->storage->timelineFor($attemptId, 'job');

        [$groups, $footerTabs] = Nav::grouped();
        $tracks = $this->buildTracks($root, $children, 'JOB');

        return view('monitor::job-attempt-page', [
            'root' => $root,
            'tracks' => $tracks,
            'defaultTrack' => $this->defaultTrackId($tracks, $jobId),
            'summary' => $this->eventsSummary($children),
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'tab' => 'jobs',
            'range' => [],
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'timezone' => Format::timezone(),
            'threshold' => (int) config('monitor.thresholds.job', 1000),
        ]);
    }

    /**
     * @return array<string, array{count: int, duration: float}>
     */
    protected function eventsSummary(Collection $children): array
    {
        $summary = collect(self::SUMMARY_TYPES)
            ->flip()
            ->map(fn () => ['count' => 0, 'duration' => 0])
            ->all();

        foreach ($children as $row) {
            $key = self::SUMMARY_TYPES[$row->type] ?? null;

            if ($key === null) {
                continue;
            }

            $summary[$key]['count']++;
            $summary[$key]['duration'] += (float) ($row->duration ?? 0);
        }

        $summary['queries']['duplicates'] = Sql::duplicateCount($children->where('type', 'slow_query'));

        return $summary;
    }

    /**
     * Walks up from this job attempt to whatever dispatched it — its own
     * 'queued' placeholder (found via the job_id they share, see
     * Recorders\Jobs), then that placeholder's own request_id/type. A
     * request/command/scheduled task root ends the walk immediately; a job
     * root means it was dispatched by *another* job attempt, so the walk
     * keeps climbing (that attempt's own job_id, if it has one) until it
     * either reaches a non-job root or runs out of ancestry — capped at a
     * handful of levels as a guard against bad/circular data, not because
     * job-dispatches-job chains are expected to run deeper than that in
     * practice. Returns null when there's nothing tracked to redirect to
     * (no job_id at all, or the trail goes cold), meaning this job attempt
     * should render its own standalone timeline instead.
     */
    protected function ancestorUrl(object $root): ?string
    {
        $jobId = $root->payload['job_id'] ?? null;
        $since = CarbonImmutable::now()->subDays(30);

        for ($guard = 0; $jobId !== null && $guard < 5; $guard++) {
            $queuedEntry = $this->storage->findQueuedJobByJobId($jobId, $since);

            if ($queuedEntry === null) {
                return null;
            }

            $dispatcherId = $queuedEntry->request_id;
            $dispatcherType = $this->storage->rootTypesFor([$dispatcherId])->get($dispatcherId);

            $url = match ($dispatcherType) {
                'request' => route('monitor.requests.show', $dispatcherId),
                'command' => route('monitor.commands.runs.show', $dispatcherId),
                'scheduled_task' => route('monitor.schedule.runs.show', $dispatcherId),
                default => null,
            };

            if ($url !== null) {
                return $url;
            }

            if ($dispatcherType !== 'job') {
                return null; // Unresolvable dispatcher type — nothing to link to.
            }

            // Dispatched from another job attempt — that attempt is itself
            // the topmost tracked ancestor unless IT was also dispatched by
            // something (i.e. has its own job_id to keep climbing with).
            $dispatcherRoot = $this->storage->findByRequestId($dispatcherId, 'job');
            $jobId = $dispatcherRoot->payload['job_id'] ?? null;

            if ($jobId === null) {
                return route('monitor.jobs.attempts.show', $dispatcherId);
            }
        }

        return null;
    }
}
