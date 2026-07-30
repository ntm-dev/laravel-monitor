<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

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
     *                                                                                                                Storage::jobExecutionsByJobId(), keyed by job_id — splices each dispatched
     *                                                                                                                job's own sub-timeline into this one (see spliceJobExecutions()) instead
     *                                                                                                                of leaving it a dead-end placeholder. Omit when the caller doesn't need
     *                                                                                                                this (e.g. a page that doesn't want the extra queries).
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
            $events = self::spliceJobExecutions($events, $rawEvents, $jobExecutions, $root);
        }

        $events = self::assignLanes($events);

        self::assignDuplicateColors($events);

        return array_merge([$requestEntry], $phases, $events);
    }

    /**
     * Replaces each dispatched-job placeholder ('queue' type, still labelled
     * "Queued Job" for a job with no outcome yet) that a later processed/
     * failed/released outcome has since landed for with that outcome's own
     * row, plus that job's own children nested directly under it — mirrors
     * Nightwatch's single merged trace view instead of a dead-end
     * placeholder. A job still only 'queued' keeps its plain placeholder.
     *
     * Repositioning is by wall clock, not proportional: a queue worker can
     * pick a job up seconds or minutes after dispatch, far outside the
     * dispatching root's own (often sub-second) duration — rather than
     * inventing a second, independently-zoomed scale for the nested
     * section, this places it at its real elapsed offset on the SAME scale
     * the rest of the timeline uses, same as Nightwatch itself, accepting
     * that a slow-to-process job can make the root's own bar read as
     * comparatively tiny. `created_at` is only second-precision in storage
     * (see the migration's plain `timestamp` column), so this offset is
     * accurate to the nearest second, not the millisecond — immaterial next
     * to gaps that are themselves typically several seconds or more.
     *
     * @param  TimelineEntry[]  $events
     * @param  Collection<int, object>  $rawEvents  the same rows $events was built from, kept around to read payload['job_id']
     * @param  Collection<string, Collection<int, object{outcome: object, children: Collection}>>  $jobExecutions
     * @return TimelineEntry[]
     */
    protected static function spliceJobExecutions(array $events, Collection $rawEvents, Collection $jobExecutions, object $root): array
    {
        $rawById = $rawEvents->keyBy('id');
        $rootStart = (float) CarbonImmutable::parse($root->created_at)->format('U.u');
        $merged = [];

        foreach ($events as $entry) {
            if ($entry->type !== 'queue') {
                $merged[] = $entry;

                continue;
            }

            $jobId = $rawById->get((int) $entry->id)?->payload['job_id'] ?? null;
            $executions = $jobId !== null ? $jobExecutions->get($jobId) : null;

            if ($executions === null || $executions->isEmpty()) {
                $merged[] = $entry;

                continue;
            }

            foreach ($executions as $index => $execution) {
                array_push($merged, ...self::jobExecutionEntries($entry, $execution, $index, $rootStart));
            }
        }

        return $merged;
    }

    /**
     * @return TimelineEntry[]
     */
    protected static function jobExecutionEntries(TimelineEntry $placeholder, object $execution, int $attemptIndex, float $rootStart): array
    {
        $outcome = $execution->outcome;
        $outcomeDuration = $outcome->duration !== null ? (float) $outcome->duration : null;

        // The outcome's own created_at is stamped when it finished (see
        // Recorders\Jobs) — its processing start is that minus its own
        // duration, the same math the job's own standalone timeline uses.
        $processingStartedAt = (float) CarbonImmutable::parse($outcome->created_at)->format('U.u') - (($outcomeDuration ?? 0) / 1000);

        $start = max(0.0, ($processingStartedAt - $rootStart) * 1000);

        $outcomeEntry = new TimelineEntry(
            id: 'job-outcome-'.$outcome->id,
            type: 'queue',
            label: $placeholder->label,
            start: $start,
            duration: $outcomeDuration,
            parentId: $placeholder->parentId,
            metadata: array_merge($placeholder->metadata, array_filter([
                'subtype' => $outcome->subtype,
                'attempt' => $attemptIndex + 1,
                'queue' => $outcome->payload['queue'] ?? null,
                'connection' => $outcome->payload['connection'] ?? null,
                // This outcome's own unique attempt id (distinct from the
                // job_id it shares with sibling attempts/the placeholder) —
                // what monitor.jobs.attempts.show needs to link to its own
                // standalone waterfall, for a viewer who wants that page's
                // event-summary cards rather than this merged view.
                'attempt_request_id' => $outcome->request_id,
            ], fn ($value) => $value !== null)),
        );

        $entries = [$outcomeEntry];

        foreach ($execution->children as $childRow) {
            if ($childRow->type === 'log') {
                continue;
            }

            $map = self::EVENT_TYPES[$childRow->type] ?? ['type' => $childRow->type, 'label' => ucfirst($childRow->type)];
            $childOffset = (float) ($childRow->start_offset ?? 0);

            $entries[] = new TimelineEntry(
                id: (string) $childRow->id,
                type: $map['type'],
                label: self::labelFor($childRow, $map['label']),
                start: $start + $childOffset,
                duration: $childRow->duration !== null ? (float) $childRow->duration : null,
                parentId: $outcomeEntry->id,
                metadata: self::metadataFor($childRow),
            );
        }

        return $entries;
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
