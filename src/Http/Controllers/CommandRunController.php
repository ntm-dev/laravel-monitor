<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Http\Controllers\Concerns\MergesJobTimelines;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Preferences;
use LaravelMonitor\Support\Sql;

/**
 * Renders the standalone Command Run Detail page: one artisan command
 * execution and every event it triggered (queries, mail, notifications,
 * cache, dispatched jobs), on the same waterfall timeline used for requests
 * and job attempts. Owns its own route (`monitor.commands.runs.show`), same
 * as RequestDetailController/JobAttemptController.
 */
class CommandRunController
{
    use MergesJobTimelines;

    /**
     * Recorder type => events-summary bucket key. No 'command' entry —
     * a command run's own timeline shouldn't summarise itself.
     */
    protected const SUMMARY_TYPES = [
        'query' => 'queries',
        'cache' => 'cache',
        'mail' => 'mail',
        'notification' => 'notifications',
        'outgoing_request' => 'outgoing',
        'job' => 'jobs',
        'lazy_loading' => 'lazy_loading',
    ];

    public function __construct(protected TimelineStorage $storage)
    {
    }

    public function __invoke(string $runId, ?string $jobId = null): View
    {
        app()->setLocale(Preferences::locale());

        $root = $this->storage->findByRequestId($runId, 'command');

        abort_unless($root !== null, 404);

        $children = $this->storage->timelineFor($runId, 'command');

        // A command-based scheduled task's own subprocess mints its own
        // fresh id (see Monitor::beginCommandRun()) and stamps which task
        // dispatched it into its own payload instead (see
        // Monitor::finalizePendingCommand()) — looked up here via the same
        // correlation_id mechanism Recorders\Mail/Notifications use to pair
        // their own two entries (see Contracts\TimelineStorage::findByCorrelationId())
        // to link back to it from the General card, rather than sharing a
        // timeline with it: everything on this page happened after the
        // scheduler already finished dispatching this run, so it belongs on
        // this run's own clock, not the scheduler's.
        $scheduledTaskRunId = $root->payload['correlation_id'] ?? null;
        $scheduledTask = $scheduledTaskRunId !== null
            ? $this->storage->findByRequestId($scheduledTaskRunId, 'scheduled_task')
            : null;

        // A run recorded before the correlation_id link above existed shared
        // its request_id with the scheduled_task row outright instead (see
        // Monitor::beginCommandRun()'s old $inheritedId behavior) — filtered
        // out of $children rather than drawn as its own bar: it is this
        // run's *parent*, recorded back in the scheduler's process against
        // that process's own clock, so it drew a bar at offset 0 while being
        // filed under whichever phase it was tagged with, i.e. a 20ms
        // "SCHEDULED TASK" sitting inside BOOTSTRAP but listed under ACTION.
        if ($scheduledTask === null) {
            $scheduledTask = $children->firstWhere('type', 'scheduled_task');
        }

        $children = $children->reject(fn (object $row) => $row->type === 'scheduled_task')->values();

        [$groups, $footerTabs] = Nav::grouped();
        $tracks = $this->buildTracks($root, $children, 'COMMAND');

        return view('monitor::command-run-page', [
            'root' => $root,
            'scheduledTask' => $scheduledTask,
            'tracks' => $tracks,
            'defaultTrack' => $this->defaultTrackId($tracks, $jobId),
            'summary' => $this->eventsSummary($children),
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'tab' => 'commands',
            'range' => [],
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'timezone' => Format::timezone(),
            'threshold' => (int) config('monitor.thresholds.command', 1000),
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
}
