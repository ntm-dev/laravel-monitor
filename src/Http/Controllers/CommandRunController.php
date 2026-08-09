<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\Storage;
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
        'slow_query' => 'queries',
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
        app()->setLocale(Preferences::locale());

        $root = $this->storage->findByRequestId($runId, 'command');

        abort_unless($root !== null, 404);

        $children = $this->storage->timelineFor($runId, 'command');

        // A command-based scheduled task's subprocess adopts the task's own
        // run id (see Monitor::beginCommandRun()), so the `scheduled_task`
        // row shares this timeline — but it is this run's *parent*, recorded
        // back in the scheduler's process against that process's own clock.
        // Left among the children it drew a bar at offset 0 (its own root
        // offset) while being filed under whichever phase it was tagged
        // with, i.e. a 20ms "SCHEDULED TASK" sitting inside BOOTSTRAP but
        // listed under ACTION. It belongs in the General card as a link to
        // the task's own run page instead.
        $scheduledTask = $children->firstWhere('type', 'scheduled_task');
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

        $summary['queries']['duplicates'] = Sql::duplicateCount($children->where('type', 'slow_query'));

        return $summary;
    }
}
