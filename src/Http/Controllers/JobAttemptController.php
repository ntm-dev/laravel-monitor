<?php

namespace LaravelMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Http\Controllers\Concerns\MergesJobTimelines;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Sql;

/**
 * Job Attempt Detail page (`monitor.jobs.attempts.show`). Redirects to the
 * dispatching root's page when one is tracked, since that timeline already
 * includes this job; otherwise renders the attempt's own timeline.
 */
class JobAttemptController
{
    use MergesJobTimelines;

    /**
     * Recorder type => events-summary bucket key. No 'job': an attempt doesn't summarise itself.
     */
    protected const SUMMARY_TYPES = [
        'query' => 'queries',
        'cache' => 'cache',
        'mail' => 'mail',
        'notification' => 'notifications',
        'outgoing_request' => 'outgoing',
        'lazy_loading' => 'lazy_loading',
    ];

    public function __construct(protected TimelineStorage $storage)
    {
    }

    public function __invoke(string $attemptId, ?string $jobId = null): View|RedirectResponse
    {
        $root = $this->storage->findByRequestId($attemptId, 'job');

        abort_unless($root !== null, 404);

        if (($ancestorUrl = $this->ancestorUrl($root)) !== null) {
            // Trailing request_id segment expands this job's track on the landing
            // page (see MergesJobTimelines::defaultTrackId()).
            return redirect("{$ancestorUrl}/{$root->request_id}");
        }

        $children = $this->storage->timelineFor($attemptId, 'job');

        [$groups, $footerTabs] = Nav::grouped();
        $tracks = $this->buildTracks($root, $children, 'JOB');

        return view('monitor::job-attempt-page', [
            'root' => $root,
            'tracks' => $tracks,
            'defaultTrack' => $this->defaultTrackId($tracks, $jobId),
            'scrollToOutcomeId' => $jobId,
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

        $summary['queries']['duplicates'] = Sql::duplicateCount($children->where('type', 'query'));

        return $summary;
    }

    /**
     * Page URL of the topmost tracked dispatcher, found via each job's 'queued'
     * entry (shared job_id) and climbing through job-dispatched jobs, capped at
     * 5 levels. Null when nothing tracked dispatched it.
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
                'request' => $this->requestUrl($dispatcherId),
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

            // Dispatched by another job attempt: keep climbing if it has a job_id.
            $dispatcherRoot = $this->storage->findByRequestId($dispatcherId, 'job');
            $jobId = $dispatcherRoot->payload['job_id'] ?? null;

            if ($jobId === null) {
                return route('monitor.jobs.attempts.show', $dispatcherId);
            }
        }

        return null;
    }

    /**
     * Route-hashed request URL when the request's key is known, else the plain one.
     */
    protected function requestUrl(string $requestId): string
    {
        $key = $this->storage->rootLabelsFor([$requestId])->get($requestId);

        if ($key === null) {
            return route('monitor.requests.show', $requestId);
        }

        return route('monitor.requests.routes.request', ['hash' => KeyHash::for($key), 'requestId' => $requestId]);
    }
}
