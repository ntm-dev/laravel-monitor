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
 * execution and every event it triggered (queries, mail, notifications,
 * cache, dispatched jobs), on the same waterfall timeline used for requests,
 * job attempts and command runs. Owns its own route
 * (`monitor.schedule.runs.show`), same as JobAttemptController/
 * CommandRunController.
 *
 * A command-based task (`Schedule::command()`) always runs the actual
 * artisan command in a *separate* `php artisan` subprocess, even when
 * scheduled "in the foreground" (see Illuminate\Console\Scheduling\Event::execute()).
 * That subprocess's own `command` entry (and everything it in turn triggers)
 * still lands in this same timeline: its id rides across the process
 * boundary via Laravel's own Context dehydration/hydration rather than
 * anything this controller needs to know about — see
 * Monitor::beginScheduledTaskRun()/Recorders\Commands::recordStarting().
 */
class ScheduleRunController
{
    use MergesJobTimelines;

    /**
     * Recorder type => events-summary bucket key. No 'command' entry — a
     * command-based task's own nested command run shows up on the timeline
     * itself (see Support\Timeline::EVENT_TYPES), not as its own summary
     * tile, mirroring Nightwatch's own scheduled-task detail page.
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

    public function __invoke(string $runId): View
    {
        $root = $this->storage->findByRequestId($runId, 'scheduled_task');

        abort_unless($root !== null, 404);

        $children = $this->storage->timelineFor($runId, 'scheduled_task');

        [$groups, $footerTabs] = Nav::grouped();
        $tracks = $this->buildTracks($root, $children, 'SCHEDULED TASK');

        return view('monitor::schedule-run-page', [
            'root' => $root,
            'tracks' => $tracks,
            'defaultTrack' => $this->defaultTrackId($tracks),
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

        $summary['queries']['duplicates'] = Sql::duplicateCount($children->where('type', 'slow_query'));

        return $summary;
    }
}
