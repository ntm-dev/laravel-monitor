<?php

namespace LaravelMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Livewire\Concerns\BuildsExceptionDetail;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Livewire\Issues;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Preferences;

/**
 * Renders the standalone Issue Detail page (route: monitor.issues.show) and
 * handles its Status/Priority mutations via plain POST + redirect back —
 * same convention as SettingsController — rather than a Livewire component,
 * since edits here are infrequent and don't need live reactivity. Owns its
 * own route, same family as RequestDetailController/JobAttemptController.
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
            'openIssueCount' => $this->storage->openIssueCount(),
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'statuses' => Issues::STATUSES,
            'priorities' => Format::PRIORITIES,
        ];

        $data = $issue->type === 'exception'
            ? $this->exceptionData($issue->key)
            : $this->performanceData($issue->type, $issue->key);

        return view('monitor::issue-detail-page', $shared + $data);
    }

    public function updateStatus(Request $request, string $uuid): RedirectResponse
    {
        $issue = $this->storage->findIssueByUuid($uuid);

        abort_unless($issue !== null, 404);

        $status = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Issues::STATUSES)],
        ])['status'];

        $this->storage->setIssueStatus($issue->type, $issue->key, $status);

        return redirect()->route('monitor.issues.show', $uuid);
    }

    public function updatePriority(Request $request, string $uuid): RedirectResponse
    {
        $issue = $this->storage->findIssueByUuid($uuid);

        abort_unless($issue !== null, 404);

        $priority = $request->validate([
            'priority' => ['required', 'string', 'in:'.implode(',', array_keys(Format::PRIORITIES))],
        ])['priority'];

        $this->storage->setIssuePriority($issue->type, $issue->key, $priority);

        return redirect()->route('monitor.issues.show', $uuid);
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
            'occurrences' => $occurrences->take(50)->map(fn ($row) => (object) [
                'date' => Format::datetime($row->created_at),
                'server' => $row->payload['server'] ?? null,
                'message' => $row->payload['message'] ?? null,
                'user' => $row->user_id !== null ? ($names[$row->user_id] ?? "User #{$row->user_id}") : null,
            ]),
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
            'targetUrl' => route('monitor.dashboard', ['tab' => $area['tab']] + (in_array($type, ['request', 'job'], true) ? ['key' => $key] : [])),
        ];
    }
}
