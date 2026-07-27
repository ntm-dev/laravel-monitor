<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Sql;
use LaravelMonitor\Support\Timeline;
use LaravelMonitor\Support\TimelineEntry;

/**
 * Lists every occurrence of one event type (queries, cache, mail, ...)
 * recorded against a single request, job attempt, or command run — the
 * page an EventSummary card links to. Neither of the two existing places
 * that show this data fits: the aggregate per-type tabs (Queries, Mail, ...)
 * span every request ever recorded, not just this one, and scrolling to the
 * waterfall timeline doesn't scale past a handful of events.
 */
class EventListController
{
    /** EventSummary card key => the `type` column value it groups. */
    public const TYPES = [
        'queries' => 'slow_query',
        'cache' => 'cache',
        'mail' => 'mail',
        'notifications' => 'notification',
        'jobs' => 'job',
        'outgoing' => 'outgoing_request',
        'lazy_loading' => 'lazy_loading',
    ];

    /** EventSummary card key => page heading. */
    protected const LABELS = [
        'queries' => 'Queries',
        'cache' => 'Cache',
        'mail' => 'Mail',
        'notifications' => 'Notifications',
        'jobs' => 'Queued Jobs',
        'outgoing' => 'Outgoing Requests',
        'lazy_loading' => 'Lazy Loads',
    ];

    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $rootId, string $type, string $rootType = 'request'): View
    {
        $recorderType = self::TYPES[$type] ?? null;

        abort_unless($recorderType !== null, 404);

        $root = $this->storage->findByRequestId($rootId, $rootType);

        abort_unless($root !== null, 404);

        $rows = $this->storage->timelineFor($rootId, $rootType)
            ->where('type', $recorderType)
            ->sortBy('start_offset')
            ->values()
            ->map(function (object $row) use ($type) {
                $entry = Timeline::eventEntry($row, []);

                return [
                    'entry' => $entry,
                    // Queries have no useful label ("Query") — the SQL itself
                    // is the actual content; every other type's label already
                    // says what happened (class name, cache key, ...).
                    'detail' => trim((string) ($entry->metadata['sql'] ?? $entry->label)),
                    'url' => $this->rowUrl($type, $entry),
                    // TimelineEntry's own metadata['created_at'] is already an
                    // ISO string (see Support\Timeline::metadataFor()) — the
                    // raw row's Carbon instance is what Format::datetime() wants.
                    'createdAt' => $row->created_at,
                ];
            });

        [$groups, $footerTabs] = Nav::grouped();

        return view('monitor::event-list-page', [
            'rows' => $rows,
            'typeLabel' => self::LABELS[$type],
            'backUrl' => $this->backUrl($rootType, $rootId, $root),
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'tab' => match ($rootType) {
                'job' => 'jobs',
                'command' => 'commands',
                default => 'requests',
            },
            'range' => [],
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'timezone' => Format::timezone(),
        ]);
    }

    /** Only queries/notifications/mail have their own detail page to jump to. */
    protected function rowUrl(string $type, TimelineEntry $entry): ?string
    {
        return match ($type) {
            'queries' => route('monitor.queries.show', [
                'hash' => KeyHash::for(Sql::normalizeKey($entry->metadata['sql'] ?? $entry->label)),
            ]),
            'notifications' => route('monitor.notifications.sends.show', [
                'hash' => KeyHash::for($entry->metadata['key']), 'id' => $entry->id,
            ]),
            'mail' => route('monitor.mail.sends.show', [
                'hash' => KeyHash::for($entry->metadata['key']), 'id' => $entry->id,
            ]),
            default => null,
        };
    }

    protected function backUrl(string $rootType, string $rootId, object $root): string
    {
        return match ($rootType) {
            'job' => route('monitor.jobs.attempts.show', $rootId),
            'command' => route('monitor.commands.runs.show', $rootId),
            // Matches the hashed-path convention every other requests.* link
            // uses (see Support\KeyHash) rather than the older flat
            // monitor.requests.show route.
            default => route('monitor.requests.routes.request', [
                'hash' => KeyHash::for($root->key ?? ''),
                'requestId' => $rootId,
            ]),
        };
    }
}
