<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Preferences;
use LaravelMonitor\Support\Timeline;

/**
 * Renders the standalone Command Run Detail page: one artisan command
 * execution and every event it triggered (queries, mail, notifications,
 * cache, dispatched jobs), on the same waterfall timeline used for requests
 * and job attempts. Owns its own route (`monitor.commands.runs.show`), same
 * as RequestDetailController/JobAttemptController.
 */
class CommandRunController
{
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

    public function __invoke(string $runId): View
    {
        app()->setLocale(Preferences::locale());

        $root = $this->storage->findByRequestId($runId, 'command');

        abort_unless($root !== null, 404);

        $children = $this->storage->timelineFor($runId, 'command');

        [$groups, $footerTabs] = Nav::grouped();

        return view('monitor::command-run-page', [
            'root' => $root,
            'timeline' => Timeline::build($root, $children),
            'totalDuration' => max(1, (int) ($root->duration ?? 0)),
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

        return $summary;
    }
}
