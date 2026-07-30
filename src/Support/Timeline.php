<?php

namespace LaravelMonitor\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LaravelMonitor\View\Components\Requests\TimelineRow;

/**
 * Builds the ordered list of TimelineEntry rows shown on the Request Detail
 * timeline: the request root, its best-effort lifecycle phases, and every
 * correlated event (queries, cache, mail, notifications, jobs, outgoing
 * requests), each attributed to the phase it fell within and stacked into
 * non-overlapping lanes so concurrent events stay readable.
 */
class Timeline
{
    public const PHASES = ['bootstrap', 'middleware', 'controller', 'render', 'unwinding', 'sending', 'terminating'];

    /**
     * Phase name => display label, for the few phases whose name doesn't
     * read well through a plain ucfirst() (see phaseEntries()).
     */
    protected const PHASE_LABELS = [
        'unwinding' => 'Middleware',
    ];

    /**
     * Recorder type => timeline type + fallback label. Add a row here to
     * surface a new recorder type on the timeline — no Blade changes needed.
     */
    protected const EVENT_TYPES = [
        'slow_query' => ['type' => 'query', 'label' => 'Query'],
        'cache' => ['type' => 'cache', 'label' => 'Cache'],
        'mail' => ['type' => 'mail', 'label' => 'Mail'],
        'notification' => ['type' => 'notification', 'label' => 'Notification'],
        'job' => ['type' => 'queue', 'label' => 'Queued Job'],
        'outgoing_request' => ['type' => 'http', 'label' => 'Outgoing Request'],
        'lazy_loading' => ['type' => 'lazy_loading', 'label' => 'Lazy Load'],
        // A command-based scheduled task's own `php artisan` subprocess
        // reports its own 'command' entry tagged with the scheduled task's
        // id (see Monitor::beginScheduledTaskRun()) — nesting it here as a
        // child, exactly like 'job' above, is what makes it show up as a
        // "HANDLE ..." row under the SCHEDULED TASK root instead of only
        // ever rendering as its own separate root (CommandRunController).
        'command' => ['type' => 'command', 'label' => 'Command'],
    ];

    /**
     * Tailwind colour names handed out to duplicate-SQL groups on the
     * timeline dot (see TimelineRow) — excludes blue (the default dot
     * colour) and rose (exceptions) so a duplicate group is never confused
     * with either. One group => one colour, picked deterministically from
     * the normalized SQL shape so it stays the same across renders/reloads
     * instead of reshuffling every page load.
     */
    protected const DUPLICATE_COLORS = [
        'emerald', 'amber', 'fuchsia', 'cyan', 'violet', 'teal', 'orange', 'indigo', 'pink', 'lime',
    ];

    /**
     * @param  object  $root  the `request` row from Storage::findByRequestId()
     * @param  Collection<int, object>  $children  rows from Storage::timelineFor()
     * @param  ?Collection<string, Collection<int, object{outcome: object, children: Collection}>>  $jobExecutions  from
     *                                                                                                                Storage::jobExecutionsByJobId(), keyed by job_id — attaches each
     *                                                                                                                dispatched job's own outcome(s) (see attachJobExecutions()) as an
     *                                                                                                                expandable, textual sub-list on its dispatch entry, instead of leaving
     *                                                                                                                it a dead end. Omit when the caller doesn't need this.
     * @return TimelineEntry[]
     */
    public static function build(object $root, Collection $children, ?Collection $jobExecutions = null): array
    {
        $duration = (float) ($root->duration ?? 0);

        $requestEntry = new TimelineEntry(
            id: 'request',
            type: 'request',
            label: $root->key ?? 'Request',
            start: 0,
            duration: $duration,
            // Only requests carry an HTTP status; job/command roots leave
            // this unset, so TimelineRow's root-bar coloring falls back to
            // its neutral default for those.
            metadata: array_filter(['status' => $root->payload['status'] ?? null], fn ($value) => $value !== null),
        );

        $phases = self::phaseEntries($root->payload['phases'] ?? [], $root->payload['route_action'] ?? null);

        $rawEvents = $children->reject(fn (object $row) => $row->type === 'log');
        $events = $rawEvents->map(fn (object $row) => self::eventEntry($row, $phases))->all();

        if ($jobExecutions !== null && $jobExecutions->isNotEmpty()) {
            self::attachJobExecutions($events, $rawEvents, $jobExecutions);
        }

        $events = self::assignLanes($events);

        self::assignDuplicateColors($events);

        return array_merge([$requestEntry], $phases, $events);
    }

    /**
     * Stamps each dispatched-job entry ('queue' type — still just the
     * dispatch-time marker, its own duration/position on the timeline
     * unchanged) with its resolved outcome(s) (processed/failed/released —
     * more than one on a retry) as plain text, under
     * `metadata['executions']` — the tree pane renders this as an
     * expandable list beneath the dispatch row (mirroring the vendor-frame
     * collapse in stack-trace.blade.php), not a second proportional bar: a
     * queue worker can pick a job up seconds or minutes after dispatch, far
     * outside the dispatching root's own (often sub-second) duration, so
     * there's no time scale the two could share without either the root's
     * own bar or the job's own becoming imperceptible. A job still only
     * 'queued' is untouched — nothing to expand yet.
     *
     * @param  TimelineEntry[]  $events  mutated in place
     * @param  Collection<int, object>  $rawEvents  the same rows $events was built from, kept around to read payload['job_id']
     * @param  Collection<string, Collection<int, object{outcome: object, children: Collection}>>  $jobExecutions
     */
    protected static function attachJobExecutions(array $events, Collection $rawEvents, Collection $jobExecutions): void
    {
        $rawById = $rawEvents->keyBy('id');

        foreach ($events as $entry) {
            if ($entry->type !== 'queue') {
                continue;
            }

            $jobId = $rawById->get((int) $entry->id)?->payload['job_id'] ?? null;
            $executions = $jobId !== null ? $jobExecutions->get($jobId) : null;

            if ($executions === null || $executions->isEmpty()) {
                continue;
            }

            $entry->metadata['executions'] = $executions
                ->map(fn (object $execution, int $index) => self::executionSummary($execution, $index))
                ->all();
        }
    }

    /**
     * @return array{subtype: string, duration: ?float, attempt: int, queue: ?string, connection: ?string, attempt_request_id: string, children: list<array{badge: string, detail: string, duration: ?float}>}
     */
    protected static function executionSummary(object $execution, int $attemptIndex): array
    {
        $outcome = $execution->outcome;

        return [
            'subtype' => $outcome->subtype,
            'duration' => $outcome->duration !== null ? (float) $outcome->duration : null,
            'attempt' => $attemptIndex + 1,
            'queue' => $outcome->payload['queue'] ?? null,
            'connection' => $outcome->payload['connection'] ?? null,
            // What monitor.jobs.attempts.show needs to link to this specific
            // attempt's own standalone page.
            'attempt_request_id' => $outcome->request_id,
            'children' => $execution->children
                ->reject(fn (object $row) => $row->type === 'log')
                ->map(function (object $row) {
                    $map = self::EVENT_TYPES[$row->type] ?? ['type' => $row->type, 'label' => ucfirst($row->type)];

                    return [
                        'badge' => TimelineRow::badgeFor($map['type']),
                        // labelFor() collapses every query to the literal
                        // word "Query" (see its own docblock) — the timeline
                        // proper reads the real SQL out of metadata instead
                        // (see TimelineRow::resolveDetail()); do the same
                        // here rather than showing that placeholder for
                        // every single query in the list.
                        'detail' => trim((string) ($row->payload['sql'] ?? self::labelFor($row, $map['label']))),
                        'duration' => $row->duration !== null ? (float) $row->duration : null,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * Groups this timeline's query entries by normalized SQL shape and
     * stamps every entry in a group of 2+ with the same `duplicateColor`
     * metadata — the EventSummary "N duplicates" badge highlights these
     * dots with a heartbeat animation (see timeline-row.blade.php).
     *
     * @param  TimelineEntry[]  $entries
     */
    protected static function assignDuplicateColors(array $entries): void
    {
        $groups = collect($entries)
            ->filter(fn (TimelineEntry $entry) => $entry->type === 'query')
            ->groupBy(fn (TimelineEntry $entry) => $entry->metadata['key'] ?? $entry->label);

        foreach ($groups as $key => $group) {
            if ($group->count() < 2) {
                continue;
            }

            $color = self::DUPLICATE_COLORS[crc32((string) $key) % count(self::DUPLICATE_COLORS)];

            foreach ($group as $entry) {
                $entry->metadata['duplicateColor'] = $color;
                $entry->metadata['duplicateCount'] = $group->count();
            }
        }
    }

    /**
     * @param  array<int, array{name: string, start: float, duration: float}>  $phases
     * @param  string|null  $routeAction  the request root's "Controller@method" (see
     *                                    Recorders\Requests::routeAction()) — surfaced on the
     *                                    controller phase row, since "Controller" alone names
     *                                    nothing.
     * @return TimelineEntry[]
     */
    protected static function phaseEntries(array $phases, ?string $routeAction = null): array
    {
        $byName = collect($phases)->keyBy('name');

        $entries = [];

        foreach (self::PHASES as $name) {
            $phase = $byName->get($name);

            if ($phase === null) {
                continue;
            }

            $entries[] = new TimelineEntry(
                id: 'phase-'.$name,
                type: $name,
                label: self::PHASE_LABELS[$name] ?? ucfirst($name),
                start: max(0.0, (float) $phase['start']),
                duration: max(0.0, (float) $phase['duration']),
                parentId: 'request',
                metadata: $name === 'controller' && $routeAction !== null ? ['controller' => $routeAction] : [],
            );
        }

        return $entries;
    }

    /**
     * @param  TimelineEntry[]  $phases
     */
    protected static function eventEntry(object $row, array $phases): TimelineEntry
    {
        $map = self::EVENT_TYPES[$row->type] ?? ['type' => $row->type, 'label' => ucfirst($row->type)];

        $start = max(0.0, (float) ($row->start_offset ?? 0));
        $duration = $row->duration !== null ? max(0.0, (float) $row->duration) : null;

        return new TimelineEntry(
            id: (string) $row->id,
            type: $map['type'],
            label: self::labelFor($row, $map['label']),
            start: $start,
            duration: $duration,
            parentId: self::parentPhaseId($row, $start, $phases),
            metadata: self::metadataFor($row),
        );
    }

    /**
     * The phase this entry belongs to on the timeline. Prefers the stage it
     * was live-tagged with at record time (`payload['phase']`, set by
     * Monitor::record() — accurate, since it reflects what was actually
     * executing when the entry was recorded) and only falls back to
     * matching its stored start_offset against the stored phase intervals
     * (containingPhase()) for rows persisted before that tag existed.
     *
     * @param  TimelineEntry[]  $phases
     */
    protected static function parentPhaseId(object $row, int|float $start, array $phases): string
    {
        $taggedPhase = $row->payload['phase'] ?? null;

        if ($taggedPhase !== null) {
            foreach ($phases as $phase) {
                if ($phase->type === $taggedPhase) {
                    return $phase->id;
                }
            }
        }

        return self::containingPhase($start, $phases)?->id ?? 'request';
    }

    protected static function labelFor(object $row, string $fallback): string
    {
        return match ($row->type) {
            'slow_query' => 'Query',
            'cache' => ucfirst($row->subtype ?? 'cache').' · '.($row->key ?? ''),
            'mail' => $row->key ?? $fallback,
            'notification' => $row->key ?? $fallback,
            'job' => $row->key ?? $fallback,
            'exception' => $row->payload['class'] ?? $row->key ?? $fallback,
            'lazy_loading' => class_basename($row->payload['model'] ?? $row->key ?? $fallback).'::'.($row->payload['relation'] ?? ''),
            default => $row->key ?? $fallback,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function metadataFor(object $row): array
    {
        // 'phase' is internal bookkeeping for parentPhaseId() above, not
        // something the detail panel shows.
        $metadata = Arr::except($row->payload, ['phase']) + [
            'subtype' => $row->subtype,
            'key' => $row->key,
            'duration' => $row->duration,
            'user_id' => $row->user_id,
            'created_at' => $row->created_at?->toIso8601String(),
        ];

        return array_filter($metadata, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  TimelineEntry[]  $phases
     */
    protected static function containingPhase(int|float $start, array $phases): ?TimelineEntry
    {
        foreach ($phases as $phase) {
            if ($start >= $phase->start && $start < $phase->end()) {
                return $phase;
            }
        }

        // Fall back to the last phase known to have started by then.
        $closest = null;

        foreach ($phases as $phase) {
            if ($phase->start <= $start && ($closest === null || $phase->start > $closest->start)) {
                $closest = $phase;
            }
        }

        return $closest;
    }

    /**
     * Greedy interval partitioning: each entry gets the lowest-numbered lane
     * whose previous occupant already finished, so overlapping events stack
     * instead of colliding.
     *
     * @param  TimelineEntry[]  $entries
     * @return TimelineEntry[]
     */
    protected static function assignLanes(array $entries): array
    {
        usort($entries, fn (TimelineEntry $a, TimelineEntry $b) => $a->start <=> $b->start);

        /** @var array<string, array<int, int>> $laneEndByParent */
        $laneEndByParent = [];

        foreach ($entries as $entry) {
            $parent = $entry->parentId ?? 'request';
            $laneEndByParent[$parent] ??= [];

            $lane = 0;

            while (($laneEndByParent[$parent][$lane] ?? -1) > $entry->start) {
                $lane++;
            }

            $laneEndByParent[$parent][$lane] = $entry->end();
            $entry->lane = $lane;
        }

        return $entries;
    }
}
