<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Timeline;

/**
 * Renders the standalone Job Attempt Detail page: one queued job execution
 * and every event it triggered (queries, mail, notifications, cache,
 * outgoing requests), on the same waterfall timeline used for requests —
 * mirrors Nightwatch, whose notification/mail rows link here (rather than to
 * a standalone notification/mail page) because both sides of a mail-channel
 * notification already show up side by side on this one timeline. Owns its
 * own route (`monitor.jobs.attempts.show`), same as RequestDetailController.
 */
class JobAttemptController
{
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

    public function __invoke(string $attemptId): View
    {
        $root = $this->storage->findByRequestId($attemptId, 'job');

        abort_unless($root !== null, 404);

        $children = $this->storage->timelineFor($attemptId, 'job');

        [$groups, $footerTabs] = Nav::grouped();

        return view('monitor::job-attempt-page', [
            'root' => $root,
            'timeline' => Timeline::build($root, $children),
            'totalDuration' => max(1, (int) ($root->duration ?? 0)),
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

        return $summary;
    }
}
