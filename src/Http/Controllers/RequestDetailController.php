<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Timeline;

/**
 * Renders the standalone Request Detail page for a single HTTP request:
 * header, general/user info, event summary and the lifecycle timeline.
 * Unlike the tab-based dashboard views, this page owns its own route
 * (`monitor.requests.show`) and fetches everything it needs itself.
 */
class RequestDetailController
{
    use ResolvesUserNames;

    /**
     * Recorder type => events-summary bucket key.
     */
    protected const SUMMARY_TYPES = [
        'slow_query' => 'queries',
        'cache' => 'cache',
        'mail' => 'mail',
        'notification' => 'notifications',
        'job' => 'jobs',
        'outgoing_request' => 'outgoing',
    ];

    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $requestId): View
    {
        $root = $this->storage->findByRequestId($requestId);

        abort_unless($root !== null, 404);

        $children = $this->storage->timelineFor($requestId);

        $userName = $root->user_id !== null
            ? ($this->resolveNames([$root->user_id])[$root->user_id] ?? null)
            : null;

        [$groups, $footerTabs] = Nav::grouped();

        return view('monitor::request-detail-page', [
            'root' => $root,
            'timeline' => Timeline::build($root, $children),
            'totalDuration' => max(1, (int) ($root->duration ?? 0)),
            'summary' => $this->eventsSummary($root, $children),
            'userName' => $userName,
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'tab' => 'requests',
            'range' => [],
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'timezone' => \LaravelMonitor\Support\Format::timezone(),
            'threshold' => (int) config('monitor.thresholds.request', 1000),
        ]);
    }

    /**
     * @return array<string, array{count: int, duration: float}>
     */
    protected function eventsSummary(object $root, Collection $children): array
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

        // `slow_query` rows only exist for queries at/above the configured
        // threshold, so counting them undercounts (or, as often happens,
        // shows zero for a request that ran several fast queries). The
        // request payload carries a true total incremented on every query —
        // fall back to the slow-query count for older rows recorded before
        // that counter existed.
        if (isset($root->payload['query_count'])) {
            $summary['queries']['count'] = (int) $root->payload['query_count'];
        }

        return $summary;
    }
}
