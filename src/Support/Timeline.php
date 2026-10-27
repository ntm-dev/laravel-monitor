<?php

namespace LaravelMonitor\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LaravelMonitor\ExecutionStage;

use function array_filter;
use function is_string;

/**
 * Builds the Request Detail timeline: the root, its lifecycle phases, and every
 * correlated event, each attributed to a phase and stacked into non-overlapping lanes.
 */
class Timeline
{
    /**
     * Every phase name, in ExecutionStage's declared case order.
     *
     * @return list<string>
     */
    public static function phases(): array
    {
        return array_map(fn (ExecutionStage $stage) => $stage->value, ExecutionStage::cases());
    }

    /**
     * Phase name => label where ucfirst() doesn't fit. Action's label depends
     * on the root type, so phaseEntries() picks it.
     */
    protected const PHASE_LABELS = [
        'unwinding' => 'Middleware',
    ];

    /** Recorder type => timeline type + fallback label. */
    protected const EVENT_TYPES = [
        'query' => ['type' => 'query', 'label' => 'Query'],
        'cache' => ['type' => 'cache', 'label' => 'Cache'],
        'mail' => ['type' => 'mail', 'label' => 'Mail'],
        'notification' => ['type' => 'notification', 'label' => 'Notification'],
        'job' => ['type' => 'queue', 'label' => 'Queued Job'],
        'outgoing_request' => ['type' => 'http', 'label' => 'Outgoing Request'],
        'lazy_loading' => ['type' => 'lazy_loading', 'label' => 'Lazy Load'],
        // A scheduled task's artisan subprocess records a 'command' entry tagged
        // with the task's id (see Monitor::beginScheduledTaskRun()); shown as its child.
        'command' => ['type' => 'command', 'label' => 'Command'],
    ];

    /**
     * Tailwind colours for duplicate-SQL groups' timeline dots; excludes blue
     * (default dot) and rose (exceptions).
     */
    protected const DUPLICATE_COLORS = [
        'emerald', 'amber', 'fuchsia', 'cyan', 'violet', 'teal', 'orange', 'indigo', 'pink', 'lime',
    ];

    /**
     * @param  object  $root  the `request`/`job`/`command`/`scheduled_task` row
     * @param  Collection<int, object>  $children  rows from TimelineStorage::timelineFor()
     * @param  float  $trackStart  Unix epoch (µs precision) $root started; entry `start`
     *                             offsets (ms) are relative to it
     * @return TimelineEntry[]
     */
    public static function build(object $root, Collection $children, float $trackStart): array
    {
        $visibleChildren = $children->reject(fn (object $row) => $row->type === 'log');

        $requestEntry = new TimelineEntry(
            id: 'request',
            type: 'request',
            label: $root->key ?? 'Request',
            start: 0,
            duration: self::rootDuration($root, $visibleChildren),
            // Only request roots have a status.
            metadata: array_filter(['status' => $root->payload['response']['status'] ?? null], static fn ($value) => $value !== null),
        );

        $phases = self::phaseEntries($root->payload['phases'] ?? [], $root->payload['request']['route_action'] ?? null, $root->type, $root->payload['render'] ?? null);

        foreach ($phases as $phase) {
            if ($requestEntry->duration > 0) {
                $phase->metadata['percent'] = Format::percent($phase->duration / $requestEntry->duration * 100);
            }
        }

        $events = self::assignLanes(
            $visibleChildren
                ->map(fn (object $row) => self::eventEntry($row, $phases))
                ->all()
        );

        self::assignDuplicateColors($events);

        $entries = [$requestEntry, ...$phases, ...$events];

        self::stampPreciseTimestamps($entries, $trackStart);

        return $entries;
    }

    /**
     * Stamps started_at/ended_at from the same start/duration that positions each
     * bar, as preformatted strings (JS Date would drop the microseconds).
     *
     * @param  TimelineEntry[]  $entries
     */
    protected static function stampPreciseTimestamps(array $entries, float $trackStart): void
    {
        $timezone = ' '.Format::timezone();

        foreach ($entries as $entry) {
            $startEpoch = $trackStart + ($entry->start / 1000);

            $entry->metadata['started_at'] = self::preciseTimestamp($startEpoch).$timezone;

            if ($entry->duration !== null) {
                $entry->metadata['ended_at'] = self::preciseTimestamp($startEpoch + ($entry->duration / 1000)).$timezone;
            }
        }
    }

    protected static function preciseTimestamp(float $epoch): string
    {
        return Format::datetime(
            CarbonImmutable::createFromFormat('U.u', number_format($epoch, 6, '.', '')),
            Format::DATETIME_PRECISE,
        );
    }

    /**
     * $root->duration, stretched to the latest child end. A background scheduled
     * task's duration only covers spawning the subprocess, so its children run past it.
     */
    protected static function rootDuration(object $root, Collection $children): float
    {
        $duration = (float) ($root->duration ?? 0);

        $latestChildEnd = $children->max(
            fn (object $row) => (float) ($row->start_offset ?? 0) + (float) ($row->duration ?? 0),
        ) ?? 0.0;

        return max($duration, $latestChildEnd);
    }

    /**
     * Stamps each query in a same-shape group of 2+ with a stable `duplicateColor`
     * (crc32 of the key) and `duplicateGroup`; highlighting matches on the group,
     * since unrelated groups can share a colour.
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
                $entry->metadata['duplicateGroup'] = (string) $key;
            }
        }
    }

    /**
     * @param  array<int, array{name: string, start: float, duration: float}>  $phases
     * @param  string|null  $routeAction  a request root's "Controller@method", shown on the Action phase
     * @param  string  $rootType  the root's RecordType value; picks the Action phase's label
     * @param  array{type: string, name: string}|null  $render  the returned view / resource class,
     *                                                          shown on the Render phase
     * @return TimelineEntry[]
     */
    protected static function phaseEntries(array $phases, ?string $routeAction, string $rootType, ?array $render = null): array
    {
        $byName = collect($phases)->keyBy('name');

        $entries = [];

        foreach (self::phases() as $name) {
            $phase = $byName->get($name);

            if ($phase === null) {
                continue;
            }

            $label = match (true) {
                $name === ExecutionStage::Action->value => $rootType === 'command' ? 'Handle' : 'Controller',
                default => self::PHASE_LABELS[$name] ?? ucfirst($name),
            };

            $entries[] = new TimelineEntry(
                id: 'phase-'.$name,
                type: $name,
                label: $label,
                start: max(0.0, (float) $phase['start']),
                duration: max(0.0, (float) $phase['duration']),
                parentId: 'request',
                metadata: match (true) {
                    $name === ExecutionStage::Action->value && $routeAction !== null => ['controller' => $routeAction],
                    $name === ExecutionStage::Render->value && $render !== null => ['render' => $render],
                    default => [],
                },
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
     * The entry's phase: `payload['phase']` (tagged by Monitor::record()), else
     * the phase interval containing its start offset.
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
            'query' => 'Query',
            'cache' => ucfirst($row->subtype ?? 'cache').' · '.($row->key ?? ''),
            'mail' => $row->key ?? $fallback,
            'notification' => $row->key ?? $fallback,
            'job' => $row->key ?? $fallback,
            'exception' => $row->payload['class'] ?? $row->key ?? $fallback,
            // key is just the host (OutgoingRequests::key()); show the full URL.
            'outgoing_request' => $row->payload['url'] ?? $row->key ?? $fallback,
            'lazy_loading' => class_basename($row->payload['model'] ?? $row->key ?? $fallback).'::'.($row->payload['relation'] ?? ''),
            default => $row->key ?? $fallback,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function metadataFor(object $row): array
    {
        // 'phase' is only for parentPhaseId().
        $payload = Arr::except($row->payload, ['phase']);

        // No stored location when a text trace was captured: derive it (absolute
        // for jobs, project-relative otherwise). Exception traces are arrays.
        if (! isset($payload['location']) && isset($payload['trace']) && is_string($payload['trace'])) {
            $payload['location'] = Trace::location($payload['trace'], $row->type === RecordType::Job->value);
        }

        // A query's connection/PDO role live packed in `subtype`, not the payload.
        if ($row->type === RecordType::Query->value) {
            [$payload['connection'], $payload['connection_type']] = QueryConnection::parse($row->subtype);
        }

        $metadata = $payload + [
            'subtype' => $row->subtype,
            'key' => $row->key,
            'duration' => $row->duration,
            'user_id' => $row->user_id,
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
     * Greedy interval partitioning per parent: each entry takes the lowest lane
     * whose previous occupant has finished.
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
