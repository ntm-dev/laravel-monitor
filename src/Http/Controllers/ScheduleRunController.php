<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Http\Controllers\Concerns\MergesJobTimelines;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Sql;

/**
 * Scheduled Task Run Detail page (`monitor.schedule.runs.show`): events the
 * task triggered in the scheduler's process. A `Schedule::command()` task's
 * subprocess has its own command run page, linked via $commandRun.
 */
class ScheduleRunController
{
    use MergesJobTimelines;

    /**
     * Recorder type => events-summary bucket key. No 'command': the command run is linked, not summarised.
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
        $root = $this->storage->findByRequestId($runId, 'scheduled_task');

        abort_unless($root !== null, 404);

        // Only SUMMARY_TYPES; keeps out a 'command' row sharing this run's request_id.
        $children = $this->storage
            ->timelineFor($runId, 'scheduled_task')
            ->whereIn('type', array_keys(self::SUMMARY_TYPES));

        // The subprocess's command run (correlation_id = this run id); null for
        // `Schedule::call()` tasks.
        $commandRun = $this->storage->findByCorrelationId('command', $runId, $root->created_at);

        [$groups, $footerTabs] = Nav::grouped();
        $tracks = $this->buildTracks($root, $children, 'SCHEDULED TASK');

        return view('monitor::schedule-run-page', [
            'root' => $root,
            'commandRun' => $commandRun,
            'tracks' => $tracks,
            'defaultTrack' => $this->defaultTrackId($tracks, $jobId),
            'scrollToOutcomeId' => $jobId,
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
