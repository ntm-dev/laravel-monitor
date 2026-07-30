<?php

namespace LaravelMonitor\View\Components\Requests;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Illuminate\View\Component;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Sql;
use LaravelMonitor\Support\Timeline as TimelineSupport;
use LaravelMonitor\Support\TimelineEntry;

/**
 * Merged multi-track waterfall timeline (Nightwatch-style): one track for
 * this page's own root (request/command/scheduled task/job) and one per job
 * it dispatched that has since resolved (see
 * Http\Controllers\Concerns\MergesJobTimelines::buildTracks()), rendered as
 * a single shared timeline — one ruler, one zoom slider, one inspector
 * panel — rather than a separate widget per track.
 *
 * Every bar is positioned against ONE fixed scale for the whole page: the
 * `$defaultTrack` track's own start/duration (see
 * MergesJobTimelines::defaultTrackId() — the root by default, or a specific
 * dispatched job when landing here from that job's own link). That track
 * starts expanded; every other track starts collapsed to just its own
 * root-level bar, positioned at its *real* wall-clock offset from that fixed
 * scale — which can fall well outside 0-100% (a queue worker can pick a job
 * up long after dispatch), that's expected. Expanding/collapsing any track
 * is purely a visibility toggle (`expandedTracks` in timeline.blade.php) —
 * unlike an accordion, expanding one track never collapses another, and the
 * scale itself never moves once rendered.
 */
class Timeline extends Component
{
    /** Ruler segments between 0 and the total duration. */
    public const TICK_COUNT = 8;

    /**
     * Every row across every track, in track order: {kind: root|phase|event|divider, entry: ?TimelineEntry, track: string, rootLabel?: string, focusable?: bool}.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /**
     * Ruler ticks: {label: "50ms", pct: float 0-100, first: bool, last: bool}.
     *
     * @var list<array{label: string, pct: float, first: bool, last: bool}>
     */
    public array $ticks = [];

    /** JSON entry map (id => type/label/start/duration/metadata) for Alpine, across every track. */
    public string $entriesJson;

    /** The fixed scale every bar/tick on the page is positioned against — the `$defaultTrack` track's own duration. */
    public int $totalDuration;

    /**
     * @param  list<array{id: string, badge: string, label: string, attempt: ?int, start: float, duration: int, entries: TimelineEntry[]}>  $tracks
     */
    public function __construct(public array $tracks, public string $defaultTrack = 'root')
    {
        $primary = collect($tracks)->firstWhere('id', $defaultTrack) ?? $tracks[0];
        $totalDuration = $this->totalDuration = max(1, (int) $primary['duration']);
        $primaryStart = (float) $primary['start'];

        $multiTrack = count($tracks) > 1;
        $entriesById = [];

        foreach ($this->tracks as $track) {
            $entries = $track['entries'];
            $byId = collect($entries)->keyBy('id');
            $byParent = collect($entries)->groupBy(fn (TimelineEntry $entry) => $entry->parentId ?? 'request');

            $phases = collect(TimelineSupport::PHASES)
                ->map(fn (string $name) => $byId->get('phase-'.$name))
                ->filter()
                ->sortBy('start')
                ->values();

            $rows = $this->buildRows($byId->get('request'), $phases, $byParent);
            $orphanRows = $this->buildOrphanRows($phases, $byParent);

            // Synthetic ids ('request', 'phase-*') collide across tracks once
            // merged into one row/entry map — a real event's id (its own
            // database row id) is already globally unique, so only these
            // two need namespacing.
            foreach ([...$rows, ...$orphanRows] as $row) {
                if ($row['kind'] !== 'event') {
                    $row['entry']->id = "{$track['id']}:{$row['entry']->id}";
                }
            }

            // This track's own start, rebased onto the fixed scale (0 = the
            // *default* track's own start, not necessarily this track's) —
            // added to each of its rows' own (track-local) start below so a
            // dispatched job's attempt lands at its real offset instead of
            // resetting to 0ms.
            $barStartOffset = (float) $track['start'] - $primaryStart;

            foreach ($rows as $row) {
                $this->rows[] = $row + [
                    'track' => $track['id'],
                    'rootLabel' => $track['badge'],
                    'focusable' => $multiTrack,
                    'barStart' => $barStartOffset + $row['entry']->start,
                ];
            }

            if ($orphanRows !== []) {
                $this->rows[] = ['kind' => 'divider', 'entry' => null, 'track' => $track['id']];

                foreach ($orphanRows as $row) {
                    $this->rows[] = $row + [
                        'track' => $track['id'],
                        'rootLabel' => $track['badge'],
                        'focusable' => false,
                        'barStart' => $barStartOffset + $row['entry']->start,
                    ];
                }
            }

            // How many times each normalized SQL shape shows up among this
            // *track's own* (threshold-recorded) queries — surfaced in the
            // inspector panel as "Duplicates". Scoped per track, matching
            // Support\Timeline::assignDuplicateColors(), which stamps the
            // tree pane's coloured dot the same way.
            $queryDuplicateCounts = collect($entries)
                ->filter(fn (TimelineEntry $entry) => $entry->type === 'query')
                ->countBy(fn (TimelineEntry $entry) => Sql::normalizeKey($entry->metadata['sql'] ?? $entry->label));

            foreach ($entries as $entry) {
                $entriesById[$entry->id] = [
                    'type' => $entry->type,
                    'badge' => TimelineRow::badgeFor($entry->type),
                    'label' => $entry->label,
                    'start' => $entry->start,
                    'duration' => $entry->duration,
                    'metadata' => $entry->metadata,
                    // The colour of the duplicate-SQL group this query belongs
                    // to (see Support\Timeline::assignDuplicateColors()), null
                    // for a non-duplicate — lets the inspector panel pulse
                    // every dot in the same group when this one's selected
                    // (see selectRow() in timeline.blade.php).
                    'duplicateColor' => $entry->metadata['duplicateColor'] ?? null,
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

        $this->entriesJson = Js::from($entriesById)->toHtml();
        $this->ticks = $this->buildTicks($totalDuration);

        foreach ($this->rows as &$row) {
            if ($row['kind'] === 'divider') {
                continue;
            }

            $row['left'] = $totalDuration > 0 ? ($row['barStart'] / $totalDuration) * 100 : 0.0;
            $row['width'] = $totalDuration > 0 ? max(0.15, (($row['entry']->duration ?? 0) / $totalDuration) * 100) : 0.15;
        }
    }

    public function render(): View
    {
        return view('monitor::components.requests.timeline');
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

    /**
     * @return list<array{label: string, pct: float, first: bool, last: bool}>
     */
    protected function buildTicks(int $totalDuration): array
    {
        $milliseconds = collect(range(0, self::TICK_COUNT))
            ->map(fn (int $i) => (int) round($totalDuration * $i / self::TICK_COUNT))
            ->unique()
            ->values();

        return $milliseconds
            ->map(fn (int $ms, int $index) => [
                'label' => Format::duration($ms),
                'pct' => $totalDuration > 0 ? ($ms / $totalDuration) * 100 : 0.0,
                'first' => $index === 0,
                'last' => $index === $milliseconds->count() - 1,
            ])
            ->all();
    }
}
