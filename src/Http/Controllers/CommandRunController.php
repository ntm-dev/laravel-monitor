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
 * Command Run Detail page (`monitor.commands.runs.show`): one artisan run and
 * every event it triggered, on the shared waterfall timeline.
 */
class CommandRunController
{
    use MergesJobTimelines;

    /**
     * Recorder type => events-summary bucket key. No 'command': a run doesn't summarise itself.
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

        // A scheduled task's subprocess stores the dispatching task's run id as
        // correlation_id (see Monitor::finalizePendingCommand()); link-only, timelines stay separate.
        $scheduledTaskRunId = $root->payload['correlation_id'] ?? null;
        $scheduledTask = $scheduledTaskRunId !== null
            ? $this->storage->findByRequestId($scheduledTaskRunId, 'scheduled_task')
            : null;

        // Runs without correlation_id may share their request_id with the parent
        // scheduled_task row; link it but keep it off this run's timeline (different clock).
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
            'scrollToOutcomeId' => $jobId,
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
