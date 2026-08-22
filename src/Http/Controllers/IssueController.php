<?php

namespace LaravelMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Livewire\Concerns\BuildsExceptionDetail;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Livewire\Issues;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Preferences;

/**
 * Renders the standalone Issue Detail page (route: monitor.issues.show).
 * Status/Priority mutations live in the embedded Livewire\IssueManagePanel
 * instead of a controller action, so changing either doesn't reload the
 * exception/performance data already on the page. Owns its own route, same
 * family as RequestDetailController/JobAttemptController.
 */
class IssueController
{
    use BuildsExceptionDetail;
    use ResolvesUserNames;

    public function __construct(protected Storage $storage)
    {
    }

    public function show(string $uuid): View
    {
        app()->setLocale(Preferences::locale());

        $issue = $this->storage->findIssueByUuid($uuid);

        abort_unless($issue !== null, 404);

        [$groups, $footerTabs] = Nav::grouped();

        $shared = [
            'issue' => $issue,
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
        ];

        $data = $issue->type === 'exception'
            ? $this->exceptionData($issue->key)
            : $this->performanceData($issue->type, $issue->key);

        return view('monitor::issue-detail-page', $shared + $data);
    }

    protected function exceptionData(string $key): array
    {
        $since = CarbonImmutable::now()->subYears(5);
        $tz = Format::timezone();

        $group = $this->storage->exceptionGroups($since, null)->firstWhere('key', $key);
        $occurrences = $this->storage->recent('exception', $since, 200, null, $key, null);
        $latest = $occurrences->first();
        $payload = $latest->payload ?? [];

        $names = $this->resolveNames(
            $occurrences->pluck('user_id')->filter(fn ($id) => $id !== null)->unique()->all()
        );

        $servers = $occurrences->pluck('payload.server')->filter()->unique()->values();
        $handled = ($group?->unhandled ?? 0) === 0;

        $lastSeen = $group?->last_seen ?? $latest?->created_at;
        $firstSeen = $this->storage->firstSeen('exception', $key) ?? $group?->first_seen;
        $phpVersion = $payload['php_version'] ?? null;
        $laravelVersion = $payload['laravel_version'] ?? null;
        $occurrencesCount = $group?->count ?? $this->storage->stats('exception', $since, null, $key, null)->count;

        return [
            'type' => 'exception',
            'exists' => $latest !== null,
            'class' => $payload['class'] ?? $key,
            'message' => $payload['message'] ?? null,
            'handled' => $handled,
            'tz' => $tz,
            'phpVersion' => $phpVersion,
            'laravelVersion' => $laravelVersion,
            'frameGroups' => $this->frameGroups($payload['frames'] ?? []),
            'markdown' => $this->markdown($payload, $handled),
            'summary' => $this->summary($lastSeen, $firstSeen, $phpVersion, $laravelVersion, (int) ($group?->users ?? 0), $occurrencesCount, $servers, $tz),
            'occurrences' => $this->occurrenceRows($occurrences, $names, $this->storage),
        ];
    }

    protected function performanceData(string $type, string $key): array
    {
        $area = Issues::PERFORMANCE_AREAS[$type] ?? null;

        abort_unless($area !== null, 404);

        $since = CarbonImmutable::now()->subYears(5);
        $stats = $this->storage->stats($type, $since, null, $key, null);

        abort_unless($stats->count > 0, 404);

        return [
            'type' => $type,
            'badge' => $area['badge'],
            'label' => $type === 'job' ? class_basename($key) : Str::limit($key, 100),
            'count' => $stats->count,
            'maxDuration' => $stats->max_duration,
            'targetUrl' => match ($type) {
                'request' => route('monitor.requests.routes.show', ['hash' => KeyHash::for($key)]),
                'job' => route('monitor.jobs.show', ['hash' => KeyHash::for($key)]),
                default => route('monitor.dashboard', ['tab' => $area['tab']]),
            },
        ];
    }
}
