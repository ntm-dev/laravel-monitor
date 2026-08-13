<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Http\Controllers\Concerns\MergesJobTimelines;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Sql;

/**
 * Renders the standalone Scheduled Task Run Detail page: one scheduled task
 * execution and every event it triggered *in the scheduler's own process*,
 * on the same waterfall timeline used for requests, job attempts and command
 * runs. Owns its own route (`monitor.schedule.runs.show`), same as
 * JobAttemptController/CommandRunController.
 *
 * A command-based task (`Schedule::command()`) always runs the actual
 * artisan command in a *separate* `php artisan` subprocess, even when
 * scheduled "in the foreground" (see Illuminate\Console\Scheduling\Event::execute())
 * — everything that subprocess triggers (queries, mail, dispatched jobs, ...)
 * happened after the scheduler already finished dispatching it, so it
 * belongs on *that run's own* timeline (see Monitor::beginCommandRun()), not
 * this one. This page instead links to that command run via the
 * correlation_id its `command` entry carries — see $commandRun below.
 */
class ScheduleRunController
{
    use MergesJobTimelines;

    /**
     * Recorder type => events-summary bucket key. No 'command' entry — a
     * command-based task's own dispatched command run is linked to (see
     * $commandRun), not folded into this page's own summary tile.
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

    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $runId, ?string $jobId = null): View
    {
        $root = $this->storage->findByRequestId($runId, 'scheduled_task');

        abort_unless($root !== null, 404);

        // Only the Event Summary's own categories (see SUMMARY_TYPES) — a
        // command-based task's own dispatched 'command' run doesn't share
        // this run's request_id any more (see $commandRun below), so this
        // filter mainly guards runs recorded before that changed.
        $children = $this->storage
            ->timelineFor($runId, 'scheduled_task')
            ->whereIn('type', array_keys(self::SUMMARY_TYPES));

        // A command-based task's own dispatched run, if any (null for a
        // closure/`Schedule::call()` task, which has no separate subprocess
        // to link to) — its `command` entry carries this run's own id as its
        // correlation_id (see Monitor::finalizePendingCommand()), the same
        // mechanism Recorders\Mail/Notifications use to pair their own two
        // entries (see Contracts\Storage::findByCorrelationId()).
        $commandRun = $this->storage->findByCorrelationId('command', $runId, $root->created_at);

        [$groups, $footerTabs] = Nav::grouped();
        $tracks = $this->buildTracks($root, $children, 'SCHEDULED TASK');

        return view('monitor::schedule-run-page', [
            'root' => $root,
            'commandRun' => $commandRun,
            'tracks' => $tracks,
            'defaultTrack' => $this->defaultTrackId($tracks, $jobId),
            'summary' => $this->eventsSummary($children),
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'tab' => 'schedule',
            'range' => [],
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'timezone' => Format::timezone(),
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
