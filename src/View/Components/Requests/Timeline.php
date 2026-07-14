<?php

namespace LaravelMonitor\View\Components\Requests;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Illuminate\View\Component;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Timeline as TimelineSupport;
use LaravelMonitor\Support\TimelineEntry;

/**
 * Request Detail timeline card (Nightwatch-style waterfall): flattens
 * Support\Timeline::build()'s entries into ordered rows (request root,
 * phase headers, one row per event), lays out the ruler ticks and exposes
 * the entry map consumed by the Alpine crosshair / inspector panel.
 */
class Timeline extends Component
{
    /** Ruler segments between 0 and the total duration. */
    public const TICK_COUNT = 8;

    /**
     * Ordered waterfall rows: {kind: root|phase|event, entry: TimelineEntry}.
     *
     * @var list<array{kind: string, entry: TimelineEntry}>
     */
    public array $rows = [];

    /**
     * Events that fell outside every recorded phase, rendered under "Other".
     *
     * @var list<array{kind: string, entry: TimelineEntry}>
     */
    public array $orphanRows = [];

    /**
     * Ruler ticks: {label: "50ms", pct: float 0-100, first: bool, last: bool}.
     *
     * @var list<array{label: string, pct: float, first: bool, last: bool}>
     */
    public array $ticks = [];

    /** JSON entry map (id => type/label/start/duration/metadata) for Alpine. */
    public string $entriesJson;

    /**
     * @param  TimelineEntry[]  $entries
     */
    public function __construct(
        public array $entries,
        public int $totalDuration,
    ) {
        $byId = collect($entries)->keyBy('id');
        $byParent = collect($entries)->groupBy(fn (TimelineEntry $entry) => $entry->parentId ?? 'request');

        $phases = collect(TimelineSupport::PHASES)
            ->map(fn (string $name) => $byId->get('phase-'.$name))
            ->filter()
            ->sortBy('start')
            ->values();

        $this->rows = $this->buildRows($byId->get('request'), $phases, $byParent);
        $this->orphanRows = $this->buildOrphanRows($phases, $byParent);
        $this->ticks = $this->buildTicks();
        $this->entriesJson = Js::from(collect($entries)->mapWithKeys(fn (TimelineEntry $entry) => [$entry->id => [
            'type' => $entry->type,
            'label' => $entry->label,
            'start' => $entry->start,
            'duration' => $entry->duration,
            'metadata' => $entry->metadata,
        ]])->all())->toHtml();
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
    protected function buildTicks(): array
    {
        $milliseconds = collect(range(0, self::TICK_COUNT))
            ->map(fn (int $i) => (int) round($this->totalDuration * $i / self::TICK_COUNT))
            ->unique()
            ->values();

        return $milliseconds
            ->map(fn (int $ms, int $index) => [
                'label' => Format::duration($ms),
                'pct' => $this->totalDuration > 0 ? ($ms / $this->totalDuration) * 100 : 0.0,
                'first' => $index === 0,
                'last' => $index === $milliseconds->count() - 1,
            ])
            ->all();
    }
}
