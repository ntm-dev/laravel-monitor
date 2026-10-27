<?php

namespace LaravelMonitor\View\Components\Requests;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Illuminate\View\Component;
use LaravelMonitor\Support\EntryId;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Sql;
use LaravelMonitor\Support\Timeline as TimelineSupport;
use LaravelMonitor\Support\TimelineEntry;

/**
 * Merged multi-track waterfall: the page's own root plus one track per resolved
 * dispatched job (see MergesJobTimelines::buildTracks()), sharing one ruler, zoom and
 * inspector. All bars sit on one fixed scale spanning every track; only the default
 * track starts expanded, and toggling a track never moves the scale.
 */
class Timeline extends Component
{
    /** Percent of its request's duration at which a query (group) is flagged in the detail panel. */
    protected const SLOW_QUERY_SHARE = 20.0;

    /** Target px width of the shortest real-duration bar; see $minWidthPx. */
    protected const MIN_ITEM_PX = 3;

    /**
     * Every row across every track, in track order: {kind: root|attempt|phase|event|divider, entry: ?TimelineEntry, track: string, rootLabel?: string, focusable?: bool, attempt?: ?int, jobStatus?: ?string, attemptsDuration?: ?float}.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /** JSON entry map (id => type/label/start/duration/metadata) for Alpine, across every track. */
    public string $entriesJson;

    /** Whether any entry carries a controller action (requests only). */
    public bool $hasController = false;

    /** Scale every bar/tick is positioned against: earliest start to latest end across all tracks. Unrounded. */
    public float $totalDuration;

    /**
     * Pane width in px at which the shortest real-duration bar is MIN_ITEM_PX wide;
     * Alpine derives maxZoom() from it. 0.0 when no entry has a non-zero duration.
     */
    public float $minWidthPx = 0.0;

    /** Row id of the attempt matching $scrollToOutcomeId, which init() scrolls to; else null. */
    public ?string $scrollTargetRowId = null;

    /**
     * @param  list<array{id: string, badge: string, label: string, start: float, duration: float, entries: TimelineEntry[], attempts?: list<array{attempt: int, status: string, outcomeId: string, start: float, duration: float, entries: TimelineEntry[]}>, totalAttemptsDuration?: float}>  $tracks
     * @param  ?string  $jobBaseUrl  this page's URL that job track rows append an outcome id to; null on pages with nowhere to navigate
     * @param  ?string  $scrollToOutcomeId  outcome id of the attempt to scroll to on load
     */
    public function __construct(public array $tracks, public string $defaultTrack = 'root', public ?string $jobBaseUrl = null, public ?string $scrollToOutcomeId = null)
    {
        $primary = collect($tracks)->firstWhere('id', $defaultTrack) ?? $tracks[0];
        $primaryDuration = max(1.0, (float) $primary['duration']);
        $primaryStart = (float) $primary['start'];

        $multiTrack = count($tracks) > 1;
        $entriesById = [];

        foreach ($this->tracks as $track) {
            // Track start relative to the default track's start; rows add their local start on top.
            $barStartOffset = (float) $track['start'] - $primaryStart;

            if (isset($track['attempts'])) {
                // Synthetic job root: a bounding box over every attempt, labelled with their summed duration.
                $this->rows[] = [
                    'kind' => 'root',
                    'entry' => new TimelineEntry(
                        id: "{$track['id']}:request",
                        type: 'request',
                        label: $track['label'],
                        start: 0,
                        duration: $track['duration'],
                    ),
                    'track' => $track['id'],
                    'rootLabel' => $track['badge'],
                    'focusable' => $multiTrack,
                    'barStart' => $barStartOffset,
                    'attempt' => null,
                    'jobStatus' => null,
                    'attemptsDuration' => $track['totalAttemptsDuration'],
                    // Links to the latest attempt (attempts are ordered oldest first).
                    'jobUrl' => $this->jobBaseUrl !== null
                        ? $this->jobBaseUrl.'/'.end($track['attempts'])['outcomeId']
                        : null,
                ];

                foreach ($track['attempts'] as $attempt) {
                    $this->addAttemptRows($track, $attempt, $barStartOffset, $entriesById);
                }

                continue;
            }

            $this->addEntrySourceRows(
                entries: $track['entries'],
                idPrefix: $track['id'],
                trackId: $track['id'],
                rootBadge: $track['badge'],
                barStartOffset: $barStartOffset,
                focusable: $multiTrack,
                attemptNumber: null,
                attemptStatus: null,
                entriesById: $entriesById,
            );
        }

        // Other tracks can start before or end after the default track, so the
        // scale spans every row and is rebased onto the earliest start.
        $nonDividerRows = collect($this->rows)->reject(fn (array $row) => $row['kind'] === 'divider');
        $minStart = min(0.0, $nonDividerRows->min('barStart') ?? 0.0);
        $maxEnd = $nonDividerRows->max(fn (array $row) => $row['barStart'] + ($row['entry']->duration ?? 0)) ?? $primaryDuration;
        $totalDuration = $this->totalDuration = max(1.0, $maxEnd - $minStart);

        $shortestDuration = $nonDividerRows
            ->map(fn (array $row) => (float) ($row['entry']->duration ?? 0))
            ->filter(fn (float $duration) => $duration > 0)
            ->min();

        $this->minWidthPx = $shortestDuration !== null
            ? ($totalDuration / $shortestDuration) * self::MIN_ITEM_PX
            : 0.0;

        if ($minStart < 0) {
            foreach ($this->rows as &$row) {
                if ($row['kind'] !== 'divider') {
                    $row['barStart'] -= $minStart;
                }
            }
            unset($row);
        }

        $this->entriesJson = Js::from($entriesById)->toHtml();
        $this->hasController = collect($entriesById)->contains(fn (array $entry) => isset($entry['metadata']['controller']));

        // A zero-duration entry borrows the shortest real bar's width; 0.15% when there is none.
        $pointWidthPct = $shortestDuration !== null
            ? ($shortestDuration / $totalDuration) * 100
            : 0.15;

        foreach ($this->rows as &$row) {
            if ($row['kind'] === 'divider') {
                continue;
            }

            $duration = (float) ($row['entry']->duration ?? 0);

            $row['left'] = $totalDuration > 0 ? ($row['barStart'] / $totalDuration) * 100 : 0.0;
            // No floor here, so every bar keeps its true proportional width.
            $row['width'] = $totalDuration > 0
                ? ($duration > 0 ? ($duration / $totalDuration) * 100 : $pointWidthPct)
                : 0.15;
        }
    }

    public function render(): View
    {
        return view('monitor::components.requests.timeline');
    }

    /**
     * One attempt's rows under its job track's synthetic root; the attempt's own
     * root entry becomes an 'attempt' row, placed at the attempt's real start.
     *
     * @param  array{id: string, badge: string, label: string, start: float, duration: int}  $track
     * @param  array{attempt: int, status: string, outcomeId: string, start: float, duration: int, entries: TimelineEntry[]}  $attempt
     */
    protected function addAttemptRows(array $track, array $attempt, float $trackBarStartOffset, array &$entriesById): void
    {
        $idPrefix = "{$track['id']}:attempt-{$attempt['attempt']}";

        // addEntrySourceRows() namespaces the attempt's root entry as "{$idPrefix}:request".
        if ($this->scrollToOutcomeId !== null && $attempt['outcomeId'] === $this->scrollToOutcomeId) {
            $this->scrollTargetRowId = "{$idPrefix}:request";
        }

        // Both starts share one origin, so their difference is the attempt's offset within the track.
        $this->addEntrySourceRows(
            entries: $attempt['entries'],
            idPrefix: $idPrefix,
            trackId: $track['id'],
            rootBadge: $track['badge'],
            barStartOffset: $trackBarStartOffset + ($attempt['start'] - $track['start']),
            focusable: false,
            attemptNumber: $attempt['attempt'],
            attemptStatus: $attempt['status'],
            entriesById: $entriesById,
        );
    }

    /**
     * Appends the rows and entry details for one bundle: a non-job track's entries
     * or one job attempt's. A non-null $attemptNumber turns the root row into an 'attempt' row.
     */
    protected function addEntrySourceRows(
        array $entries,
        string $idPrefix,
        string $trackId,
        string $rootBadge,
        float $barStartOffset,
        bool $focusable,
        ?int $attemptNumber,
        ?string $attemptStatus,
        array &$entriesById,
    ): void {
        $byId = collect($entries)->keyBy('id');
        $byParent = collect($entries)->groupBy(fn (TimelineEntry $entry) => $entry->parentId ?? 'request');

        $phases = collect(TimelineSupport::phases())
            ->map(fn (string $name) => $byId->get('phase-'.$name))
            ->filter()
            ->sortBy('start')
            ->values();

        $rows = $this->buildRows($byId->get('request'), $phases, $byParent);
        $orphanRows = $this->buildOrphanRows($phases, $byParent);

        // Synthetic ids ('request', 'phase-*') collide across bundles; event ids are already unique.
        foreach ([...$rows, ...$orphanRows] as $row) {
            if ($row['kind'] !== 'event') {
                $row['entry']->id = "{$idPrefix}:{$row['entry']->id}";
            }
        }

        foreach ($rows as $row) {
            $attemptFields = ['focusable' => $focusable, 'attempt' => null, 'jobStatus' => null];

            // Array union keeps $row's own 'kind', so override it on $row directly.
            if ($row['kind'] === 'root' && $attemptNumber !== null) {
                $row['kind'] = 'attempt';
                $attemptFields = ['focusable' => false, 'attempt' => $attemptNumber, 'jobStatus' => $attemptStatus];
            }

            $this->rows[] = $row + $attemptFields + [
                'track' => $trackId,
                'rootLabel' => $rootBadge,
                'barStart' => $barStartOffset + $row['entry']->start,
                'attemptsDuration' => null,
                // A non-job root links to the bare $jobBaseUrl, i.e. back to its own info.
                'jobUrl' => $row['kind'] === 'root' ? $this->jobBaseUrl : null,
            ];
        }

        $this->addOrphanRows($orphanRows, $phases->isNotEmpty(), $trackId, $rootBadge, $barStartOffset);

        $this->addEntryDetails($entries, $rootBadge, $attemptNumber, (float) ($byId->get('request')?->duration ?? 0), $entriesById);
    }

    /** Appends one bundle's events that fall outside every phase, under an "Other" divider. */
    protected function addOrphanRows(array $orphanRows, bool $hasPhases, string $trackId, string $rootBadge, float $barStartOffset): void
    {
        if ($orphanRows === []) {
            return;
        }

        // No divider without phases (e.g. a job attempt), where every event lands here.
        if ($hasPhases) {
            $this->rows[] = ['kind' => 'divider', 'entry' => null, 'track' => $trackId];
        }

        foreach ($orphanRows as $row) {
            $this->rows[] = $row + [
                'track' => $trackId,
                'rootLabel' => $rootBadge,
                'focusable' => false,
                'barStart' => $barStartOffset + $row['entry']->start,
                'attempt' => null,
                'jobStatus' => null,
                'attemptsDuration' => null,
                'jobUrl' => null,
            ];
        }
    }

    /**
     * The inspector panel's per-entry data for one bundle, keyed by entry id
     * into $entriesById. Runs after addEntrySourceRows() namespaces the
     * synthetic ids.
     */
    protected function addEntryDetails(
        array $entries,
        string $rootBadge,
        ?int $attemptNumber,
        float $bundleDuration,
        array &$entriesById,
    ): void {
        $attemptLabel = $attemptNumber !== null ? __('monitor::messages.common.attempt') : null;

        // Queries grouped by normalized SQL, per bundle (the panel's "Calls").
        $queryGroups = collect($entries)
            ->filter(fn (TimelineEntry $entry) => $entry->type === 'query')
            ->groupBy(fn (TimelineEntry $entry) => Sql::normalizeKey($entry->metadata['sql'] ?? $entry->label));
        $queryDuplicateCounts = $queryGroups->map(static fn (Collection $group) => $group->count());
        $queryGroupTotals = $queryGroups->map(static fn (Collection $group) => (float) $group->sum('duration'));

        foreach ($entries as $entry) {
            // Same kinds as the rows, re-derived for the panel since type 'request' is ambiguous.
            $kind = match (true) {
                $entry->parentId === null => $attemptNumber !== null ? 'attempt' : 'root',
                in_array($entry->type, TimelineSupport::phases(), true) => 'phase',
                default => 'event',
            };

            $entriesById[$entry->id] = [
                'type' => $entry->type,
                'kind' => $kind,
                // "JOB (Attempt #N)" for an attempt row.
                'badge' => $kind === 'attempt'
                    ? "{$rootBadge} ({$attemptLabel} #{$attemptNumber})"
                    : TimelineRow::badgeFor($entry->type, $entry->label),
                'label' => $entry->label,
                'start' => $entry->start,
                'duration' => $entry->duration,
                'metadata' => $entry->metadata,
                'duplicateColor' => $entry->metadata['duplicateColor'] ?? null,
                // Unique per group, unlike duplicateColor; selectRow() pulses by it.
                'duplicateGroup' => $entry->metadata['duplicateGroup'] ?? null,
                ...self::queryDetail($entry, $queryDuplicateCounts, $queryGroupTotals, $bundleDuration),
                ...self::occurrenceUrls($entry),
            ];
        }
    }

    /**
     * The inspector panel's call-count, share and detail-link fields; all null for a non-query entry.
     *
     * @param  Collection<string, int>  $duplicateCounts
     * @param  Collection<string, float>  $groupTotals
     * @return array<string, mixed>
     */
    protected static function queryDetail(TimelineEntry $entry, Collection $duplicateCounts, Collection $groupTotals, float $bundleDuration): array
    {
        if ($entry->type !== 'query') {
            return ['duplicateCount' => null, 'duplicateTotal' => null, 'duplicateShare' => null, 'durationShare' => null, 'queryUrl' => null];
        }

        $queryKey = Sql::normalizeKey($entry->metadata['sql'] ?? $entry->label);
        $isDuplicate = $duplicateCounts[$queryKey] > 1;

        return [
            'duplicateCount' => $duplicateCounts[$queryKey],
            // Summed time of the duplicate group and its share of the bundle's
            // duration; both null for a query that runs once.
            'duplicateTotal' => $isDuplicate ? $groupTotals[$queryKey] : null,
            'duplicateShare' => $isDuplicate ? self::share($groupTotals[$queryKey], $bundleDuration) : null,
            // This query's own share, only once it reaches SLOW_QUERY_SHARE.
            'durationShare' => self::share((float) $entry->duration, $bundleDuration, self::SLOW_QUERY_SHARE),
            // The stored group key is the normalized SQL shape (see
            // Recorders\Queries::record()), not the raw SQL in metadata['sql'].
            'queryUrl' => route('monitor.queries.show', ['hash' => KeyHash::for($queryKey)]),
        ];
    }

    /**
     * Per-occurrence detail page links — at most one non-null, by entry type.
     *
     * @return array<string, string|null>
     */
    protected static function occurrenceUrls(TimelineEntry $entry): array
    {
        $urls = ['notificationUrl' => null, 'mailUrl' => null, 'exceptionUrl' => null, 'outgoingUrl' => null];

        $occurrence = static fn (string $route) => route($route, ['hash' => KeyHash::for($entry->metadata['key']), 'id' => EntryId::encode((int) $entry->id)]);

        return match ($entry->type) {
            'notification' => ['notificationUrl' => $occurrence('monitor.notifications.sends.show')] + $urls,
            'mail' => ['mailUrl' => $occurrence('monitor.mail.sends.show')] + $urls,
            // Exception keys are already a fingerprint hash, so no KeyHash::for().
            'exception' => ['exceptionUrl' => route('monitor.exceptions.show', ['hash' => $entry->metadata['key']])] + $urls,
            'http' => ['outgoingUrl' => $occurrence('monitor.outgoing.sends.show')] + $urls,
            default => $urls,
        };
    }

    /** "60%": $part's share of $whole, or null when $whole is zero or the share is under $minimum. */
    protected static function share(float $part, float $whole, float $minimum = 0.0): ?string
    {
        if ($whole <= 0) {
            return null;
        }

        $share = $part / $whole * 100;

        return $share >= $minimum ? Format::percent($share) : null;
    }

    /**
     * @param  Collection<int, TimelineEntry>  $phases
     * @return list<array{kind: string, entry: TimelineEntry}>
     */
    protected function buildRows(?TimelineEntry $request, Collection $phases, Collection $byParent): array
    {
        $rows = [];

        if ($request !== null) {
            $rows[] = ['kind' => 'root', 'entry' => $request];
        }

        foreach ($phases as $phase) {
            $rows[] = ['kind' => 'phase', 'entry' => $phase];

            foreach ($byParent->get($phase->id, collect())->sortBy('start') as $event) {
                $rows[] = ['kind' => 'event', 'entry' => $event];
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, TimelineEntry>  $phases
     * @return list<array{kind: string, entry: TimelineEntry}>
     */
    protected function buildOrphanRows(Collection $phases, Collection $byParent): array
    {
        $known = $phases->pluck('id')->push('request');

        return $byParent->get('request', collect())
            ->reject(fn (TimelineEntry $entry) => $known->contains($entry->id))
            ->sortBy('start')
            ->map(fn (TimelineEntry $entry) => ['kind' => 'event', 'entry' => $entry])
            ->values()
            ->all();
    }
}
