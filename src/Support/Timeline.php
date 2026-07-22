<?php

namespace LaravelMonitor\Support;

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
    public const PHASES = ['bootstrap', 'middleware', 'controller', 'render', 'sending', 'terminating'];

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
    ];

    /**
     * @param  object  $root  the `request` row from Storage::findByRequestId()
     * @param  Collection<int, object>  $children  rows from Storage::timelineFor()
     * @return TimelineEntry[]
     */
    public static function build(object $root, Collection $children): array
    {
        $duration = (float) ($root->duration ?? 0);

        $requestEntry = new TimelineEntry(id: 'request', type: 'request', label: $root->key ?? 'Request', start: 0, duration: $duration);

        $phases = self::phaseEntries($root->payload['phases'] ?? []);

        $events = self::assignLanes(
            $children->reject(fn (object $row) => $row->type === 'log')
                ->map(fn (object $row) => self::eventEntry($row, $phases))
                ->all()
        );

        return array_merge([$requestEntry], $phases, $events);
    }

    /**
     * @param  array<int, array{name: string, start: int, duration: int}>  $phases
     * @return TimelineEntry[]
     */
    protected static function phaseEntries(array $phases): array
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
                label: ucfirst($name),
                start: max(0, (int) $phase['start']),
                duration: max(0, (int) $phase['duration']),
                parentId: 'request',
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
        $duration = max(0.0, (float) ($row->duration ?? 0));

        return new TimelineEntry(
            id: (string) $row->id,
            type: $map['type'],
            label: self::labelFor($row, $map['label']),
            start: $start,
            duration: $duration,
            parentId: self::containingPhase($start, $phases)?->id ?? 'request',
            metadata: self::metadataFor($row),
        );
    }

    protected static function labelFor(object $row, string $fallback): string
    {
        return match ($row->type) {
            'slow_query' => 'Query',
            'cache' => ucfirst($row->subtype ?? 'cache').' · '.($row->key ?? ''),
            'mail' => $row->payload['subject'] ?? $row->key ?? $fallback,
            'notification', 'job' => class_basename($row->key ?? $fallback),
            'lazy_loading' => class_basename($row->payload['model'] ?? $row->key ?? $fallback).'::'.($row->payload['relation'] ?? ''),
            default => $row->key ?? $fallback,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function metadataFor(object $row): array
    {
        $metadata = $row->payload + [
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
