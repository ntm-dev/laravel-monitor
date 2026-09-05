<?php

namespace LaravelMonitor\View\Components\Requests;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Illuminate\View\Component;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Sql;
use LaravelMonitor\Support\Timeline as TimelineSupport;
use LaravelMonitor\Support\TimelineEntry;

/**
 * Merged multi-track waterfall timeline: one track for
 * this page's own root (request/command/scheduled task/job) and one per job
 * it dispatched that has since resolved (see
 * Http\Controllers\Concerns\MergesJobTimelines::buildTracks()), rendered as
 * a single shared timeline — one ruler, one zoom slider, one inspector
 * panel — rather than a separate widget per track.
 *
 * Every bar is positioned against ONE fixed scale for the whole page, built
 * once from every track's *real* wall-clock offset relative to the
 * `$defaultTrack` track (see MergesJobTimelines::defaultTrackId() — the root
 * by default, or a specific dispatched job when landing here from that job's
 * own link): 0% is the earliest thing happening across every track (which
 * can predate the default track itself — a job worker can pick a job up long
 * after its dispatching request finished) and 100% is the latest thing
 * ending, so the ruler always has ticks under everything on the page instead
 * of stopping at the default track's own duration. The default track starts
 * expanded; every other track starts collapsed to just its own root-level
 * bar. Expanding/collapsing any track is purely a visibility toggle
 * (`expandedTracks` in timeline.blade.php) — unlike an accordion, expanding
 * one track never collapses another, and the scale itself never moves once
 * rendered.
 */
class Timeline extends Component
{
    /**
     * The shortest real (non-zero) duration bar on the page must still be
     * at least this many px wide at 1x zoom — see $minWidthPx.
     */
    protected const MIN_ITEM_PX = 3;

    /**
     * Every row across every track, in track order: {kind: root|attempt|phase|event|divider, entry: ?TimelineEntry, track: string, rootLabel?: string, focusable?: bool, attempt?: ?int, jobStatus?: ?string, attemptsDuration?: ?int}.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /** JSON entry map (id => type/label/start/duration/metadata) for Alpine, across every track. */
    public string $entriesJson;

    /**
     * The fixed scale every bar/tick on the page is positioned against —
     * spans from the earliest row's start to the latest row's end, across
     * every track (see the class docblock). Left unrounded (a float, not the
     * whole-ms int this used to be) — it's handed to Alpine as-is
     * (`totalDuration` in timeline.blade.php's x-data) for every downstream
     * ms/pct computation there (ruler ticks, the hover crosshair's ms
     * readout, ...), each of which does its own rounding at the point it
     * actually renders a number, so nothing here should round first and
     * force that later, more-informed rounding to work from an
     * already-lossy input.
     */
    public float $totalDuration;

    /**
     * The chart pane's own minimum width in px at 1x zoom (see
     * timeline.blade.php), chosen so the page's shortest real-duration bar
     * is still at least self::MIN_ITEM_PX wide — every other bar/gap is
     * measured in px against that exact same px-per-ms ratio, so the whole
     * pane stretches as one unit and every bar keeps its true proportional
     * share of the timeline (start time, end time, and the gaps between
     * bars) instead of a lone short bar being widened past its real size.
     * Left unrounded for the same reason as $totalDuration above — Alpine
     * multiplies it by the live zoom level (`zoom * minWidthPx` in
     * timeline.blade.php) to get the pane's actual CSS min-width, so
     * rounding it here would just make that later multiplication work from
     * a less accurate number for no benefit (CSS tolerates arbitrary
     * sub-pixel precision fine). 0.0 when nothing on the page has a real
     * (non-zero) duration to measure against, leaving the pane at its normal
     * viewport-fit width.
     */
    public float $minWidthPx = 0.0;

    /**
     * The specific attempt row (see addAttemptRows()) whose {@see TimelineEntry::$id}
     * matches $scrollToOutcomeId, null when that constructor param is null or
     * matches no attempt on the page. timeline-script.blade.php's init()
     * scrolls straight to this row (via its `data-row-id`) instead of merely
     * the default track's own root row — the requested attempt can otherwise
     * still sit well below the fold, e.g. a job's 3rd retry several rows
     * under its track's root.
     */
    public ?string $scrollTargetRowId = null;

    /**
     * @param  list<array{id: string, badge: string, label: string, start: float, duration: float, entries: TimelineEntry[], attempts?: list<array{attempt: int, status: string, outcomeId: string, start: float, duration: float, entries: TimelineEntry[]}>, totalAttemptsDuration?: float}>  $tracks
     * @param  ?string  $jobBaseUrl  This page's own url (see RequestDetailController::requestUrl()) a job track's own row appends its latest attempt's outcome id onto, so clicking it navigates there instead of merely expanding in place — null on every other page this component renders on (job/command/schedule detail), which has nowhere of that kind to navigate a job track to.
     * @param  ?string  $scrollToOutcomeId  The route's own trailing job-id segment forwarded by the calling controller (see MergesJobTimelines::defaultTrackId(), which the same value already selects the default *track* from) — names one specific attempt's own outcome id, so landing here from that exact attempt's link scrolls straight to its row instead of just expanding (and scrolling to the top of) its track.
     */
    public function __construct(public array $tracks, public string $defaultTrack = 'root', public ?string $jobBaseUrl = null, public ?string $scrollToOutcomeId = null)
    {
        $primary = collect($tracks)->firstWhere('id', $defaultTrack) ?? $tracks[0];
        $primaryDuration = max(1.0, (float) $primary['duration']);
        $primaryStart = (float) $primary['start'];

        $multiTrack = count($tracks) > 1;
        $entriesById = [];

        foreach ($this->tracks as $track) {
            // This track's own start, rebased onto the fixed scale (0 = the
            // *default* track's own start, not necessarily this track's) —
            // every row below adds its own (track- or attempt-local) start
            // on top of this so a dispatched job's attempt lands at its real
            // offset instead of resetting to 0ms.
            $barStartOffset = (float) $track['start'] - $primaryStart;

            if (isset($track['attempts'])) {
                // A job track has no phases/events of its own — every one of
                // those belongs to a specific attempt instead (see
                // MergesJobTimelines::jobTrack()). Its root row is therefore
                // synthetic: a bounding box spanning every attempt, shown
                // with the *sum* of their individual durations rather than
                // that (potentially much longer, once idle retry-wait time
                // is included) span — see TimelineRow's own handling of
                // 'attemptsDuration'.
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
                    // The track's own latest attempt (last = most recent —
                    // see jobTrack()'s own docblock) is what a click on this
                    // row navigates to; there's no single "outcome" for a
                    // whole track otherwise.
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

        // The primary track's own duration alone isn't a wide enough scale
        // once another track can land well outside 0-100% of it (see
        // $barStartOffset above — a queue worker can pick a job up long
        // after its dispatching request finished, or (landing here scoped to
        // that job, see MergesJobTimelines::defaultTrackId()) the request
        // that dispatched it can just as easily sit *before* 0). A ruler
        // fixed to just the primary's own span would leave every mark
        // outside it blank the moment you scroll/expand into such a track.
        // Rebase the whole page onto the earliest thing happening across
        // every track instead, so 0-100% always covers everything on it.
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

        // A zero-duration entry (an instantaneous marker, not a span) has no
        // real proportional share of the timeline to render at all — it
        // borrows the shortest real bar's own share instead of a fixed
        // magic-number percentage, so it stays exactly as wide as
        // self::MIN_ITEM_PX too rather than a size unrelated to everything
        // else on the same scale. Falls back to the pre-stretch 0.15% when
        // nothing on the page has a real duration to borrow from. Left
        // unrounded like every other value here (see $totalDuration/
        // $minWidthPx above) — PHP is this value's own final consumer (baked
        // directly into the rendered `style` attribute below, nothing
        // downstream computes with it further), but the browser accepts
        // arbitrary float precision in a CSS percentage just as happily as a
        // 4-decimal one, so rounding here would only reintroduce the exact
        // proportional drift $minWidthPx exists to avoid, for no display
        // benefit.
        $pointWidthPct = $shortestDuration !== null
            ? ($shortestDuration / $totalDuration) * 100
            : 0.15;

        foreach ($this->rows as &$row) {
            if ($row['kind'] === 'divider') {
                continue;
            }

            $duration = (float) ($row['entry']->duration ?? 0);

            $row['left'] = $totalDuration > 0 ? ($row['barStart'] / $totalDuration) * 100 : 0.0;
            // No floor on a positive-duration bar's own share: $minWidthPx
            // above already stretched the whole pane so the shortest one on
            // the page renders at self::MIN_ITEM_PX, so clamping any
            // individual bar's percentage here would only widen it past its
            // true proportional size relative to every other bar and gap.
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
     * One retry's worth of rows, nested under its job track's own (synthetic,
     * always neutral) root — see the 'attempts' branch in __construct(). Its
     * own root entry becomes this attempt's 'attempt' row instead of a
     * second 'root' (see addEntrySourceRows()'s $attemptNumber handling):
     * the track identifies *what* ran, each attempt identifies *how that one
     * try went*, at its own real position (this attempt's own start, not the
     * track's bounding-box start).
     *
     * @param  array{id: string, badge: string, label: string, start: float, duration: int}  $track
     * @param  array{attempt: int, status: string, outcomeId: string, start: float, duration: int, entries: TimelineEntry[]}  $attempt
     */
    protected function addAttemptRows(array $track, array $attempt, float $trackBarStartOffset, array &$entriesById): void
    {
        $idPrefix = "{$track['id']}:attempt-{$attempt['attempt']}";

        // addEntrySourceRows() below namespaces this attempt's own 'request'
        // entry (see Timeline::build()) as "{$idPrefix}:request" — computed
        // here rather than read back off $this->rows after the call, since
        // that id is a known, already-relied-upon format (the job track's
        // own root row hardcodes the same "{id}:request" shape above).
        if ($this->scrollToOutcomeId !== null && $attempt['outcomeId'] === $this->scrollToOutcomeId) {
            $this->scrollTargetRowId = "{$idPrefix}:request";
        }

        // $attempt['start']/$track['start'] are both offsets from the same
        // origin (the page's overall root — see MergesJobTimelines::jobTrack()),
        // so their difference is this attempt's own offset *within* the
        // track's bounding box — added on top of $trackBarStartOffset (the
        // box's own offset from the fixed scale) below. Using
        // $trackBarStartOffset + $attempt['start'] directly would double-count
        // the box's own offset for every attempt (correct only for a single-
        // attempt track, where the two happen to be equal).
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
     * Builds every row for one "request"-shaped bundle of entries — a
     * track's own top-level entries for a request/command/scheduled-task
     * track, or one job attempt's own entries for a job track (see
     * addAttemptRows()) — and appends them to $this->rows/$entriesById.
     * $attemptNumber/$attemptStatus turn what would otherwise be this
     * bundle's own 'root' row into an 'attempt' row instead (see
     * TimelineRow's handling of both kinds) — null for every non-attempt
     * caller, which keeps the normal 'root' row. A job track's own root row
     * (the one carrying the sum-of-attempts duration) is built directly in
     * __construct() instead of through here — it has no entries of its own.
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
        $attemptLabel = $attemptNumber !== null ? __('monitor::messages.common.attempt') : null;

        $byId = collect($entries)->keyBy('id');
        $byParent = collect($entries)->groupBy(fn (TimelineEntry $entry) => $entry->parentId ?? 'request');

        $phases = collect(TimelineSupport::phases())
            ->map(fn (string $name) => $byId->get('phase-'.$name))
            ->filter()
            ->sortBy('start')
            ->values();

        $rows = $this->buildRows($byId->get('request'), $phases, $byParent);
        $orphanRows = $this->buildOrphanRows($phases, $byParent);

        // Synthetic ids ('request', 'phase-*') collide across tracks/attempts
        // once merged into one row/entry map — a real event's id (its own
        // database row id) is already globally unique, so only these two
        // need namespacing.
        foreach ([...$rows, ...$orphanRows] as $row) {
            if ($row['kind'] !== 'event') {
                $row['entry']->id = "{$idPrefix}:{$row['entry']->id}";
            }
        }

        foreach ($rows as $row) {
            $isAttempt = $row['kind'] === 'root' && $attemptNumber !== null;

            // `$row + [...]` keeps $row's OWN value for any key already
            // present in it (array union favours the left side) — 'kind' is
            // one of those, so overriding it to 'attempt' has to happen on
            // $row itself first, not through the union below.
            if ($isAttempt) {
                $row['kind'] = 'attempt';
            }

            $this->rows[] = $row + [
                'track' => $trackId,
                'rootLabel' => $rootBadge,
                'focusable' => $isAttempt ? false : $focusable,
                'barStart' => $barStartOffset + $row['entry']->start,
                'attempt' => $isAttempt ? $attemptNumber : null,
                'jobStatus' => $isAttempt ? $attemptStatus : null,
                'attemptsDuration' => null,
                // The primary (non-job) track's own root row gets
                // $jobBaseUrl itself here -- the same base URL a job
                // track's own row (built directly in __construct(), not
                // here) appends an outcome id onto, but bare, since this
                // root's own info is what already shows there with no
                // trailing job id at all. Lets a visitor who navigated to a
                // job's own info click back to this root's, the way
                // switching between two different jobs' own tracks already
                // works. Every other row built through here (phase/event/
                // attempt) has nothing of that kind to navigate to.
                'jobUrl' => $row['kind'] === 'root' ? $this->jobBaseUrl : null,
            ];
        }

        if ($orphanRows !== []) {
            // The "Other" header only means something when there are real
            // phases for these events to be "other than" (a request's
            // bootstrap/middleware/controller/...) — a job attempt has no
            // phases at all (see Support\Timeline::phases()), so *every* one
            // of its events ends up here, and labelling the whole list
            // "Other" is just noise.
            if ($phases->isNotEmpty()) {
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

        // How many times each normalized SQL shape shows up among this
        // bundle's own (threshold-recorded) queries — surfaced in the
        // inspector panel as "Duplicates". Scoped per bundle (track, or one
        // job attempt), matching Support\Timeline::assignDuplicateColors(),
        // which stamps the tree pane's coloured dot the same way.
        $queryDuplicateCounts = collect($entries)
            ->filter(fn (TimelineEntry $entry) => $entry->type === 'query')
            ->countBy(fn (TimelineEntry $entry) => Sql::normalizeKey($entry->metadata['sql'] ?? $entry->label));

        foreach ($entries as $entry) {
            // 'root'/'attempt'/'phase'/'event' — same classification
            // TimelineRow's own $kind param already drives (see
            // buildRows()), re-derived here since entriesById is built
            // straight off $entries, not $this->rows. Lets the detail
            // panel (timeline-detail-panel.blade.php) branch on kind rather
            // than $entry->type, which is ambiguous for this purpose
            // ('request' is shared by every root AND attempt entry, and a
            // phase's own type varies per phase name).
            $kind = match (true) {
                $entry->parentId === null => $attemptNumber !== null ? 'attempt' : 'root',
                in_array($entry->type, TimelineSupport::phases(), true) => 'phase',
                default => 'event',
            };

            $entriesById[$entry->id] = [
                'type' => $entry->type,
                'kind' => $kind,
                // "JOB (Attempt #N)" for an attempt row -- $rootBadge is
                // already this track's own "JOB" (see MergesJobTimelines::jobTrack()),
                // and TimelineRow::badgeFor() has nothing better to offer
                // here anyway (every attempt/root entry shares the same
                // generic $entry->type === 'request', which falls back to a
                // plain, unhelpful "REQUEST").
                'badge' => $kind === 'attempt'
                    ? "{$rootBadge} ({$attemptLabel} #{$attemptNumber})"
                    : TimelineRow::badgeFor($entry->type, $entry->label),
                'label' => $entry->label,
                'start' => $entry->start,
                'duration' => $entry->duration,
                'metadata' => $entry->metadata,
                // The colour of the duplicate-SQL group this query belongs
                // to (see Support\Timeline::assignDuplicateColors()), null
                // for a non-duplicate.
                'duplicateColor' => $entry->metadata['duplicateColor'] ?? null,
                // This group's own key, null for a non-duplicate — lets the
                // inspector panel pulse every dot in the *same* group when
                // this one's selected (see selectRow() in timeline.blade.php).
                // Not duplicateColor above: two unrelated groups can share a
                // colour (only 10 in the palette), which would otherwise
                // pulse both together.
                'duplicateGroup' => $entry->metadata['duplicateGroup'] ?? null,
                'duplicateCount' => $entry->type === 'query'
                    ? $queryDuplicateCounts[Sql::normalizeKey($entry->metadata['sql'] ?? $entry->label)]
                    : null,
                // Only queries have their own detail page. The stored group key
                // is the normalized SQL shape (see Recorders\Queries::record()),
                // not the raw per-call SQL text in metadata['sql'] — normalizing
                // it again here the same way keeps this link matching the row
                // QueryDetail's exact-equality lookup expects.
                'queryUrl' => $entry->type === 'query'
                    ? route('monitor.queries.show', ['hash' => KeyHash::for(Sql::normalizeKey($entry->metadata['sql'] ?? $entry->label))])
                    : null,
                // The entry's own database id — see NotificationDetail/MailDetail —
                // for the full per-occurrence page (correlation link to the other
                // one included), which this inline panel only summarises. The
                // hash is the notification/mail *class*'s (metadata['key'], its
                // own grouping key — see Support\Timeline::metadataFor()), not
                // the entry's own id.
                'notificationUrl' => $entry->type === 'notification'
                    ? route('monitor.notifications.sends.show', ['hash' => KeyHash::for($entry->metadata['key']), 'id' => $entry->id])
                    : null,
                'mailUrl' => $entry->type === 'mail'
                    ? route('monitor.mail.sends.show', ['hash' => KeyHash::for($entry->metadata['key']), 'id' => $entry->id])
                    : null,
                // Exceptions already group by an opaque Fingerprint hash (stored
                // directly as the entry's own key) — no KeyHash::for() reverse
                // lookup needed, unlike the other detail links above (see
                // DashboardController::resolveKey()).
                'exceptionUrl' => $entry->type === 'exception'
                    ? route('monitor.exceptions.show', ['hash' => $entry->metadata['key']])
                    : null,
                // Outgoing requests only have a per-occurrence detail page (no
                // aggregate "class" mode like notifications/mail) — see
                // Http\Headings\OutgoingHeading / Livewire\OutgoingDetail.
                'outgoingUrl' => $entry->type === 'http'
                    ? route('monitor.outgoing.sends.show', ['hash' => KeyHash::for($entry->metadata['key']), 'id' => $entry->id])
                    : null,
            ];
        }
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
