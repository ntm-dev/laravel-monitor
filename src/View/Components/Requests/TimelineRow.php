<?php

namespace LaravelMonitor\View\Components\Requests;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use LaravelMonitor\Recorders\Queries;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\TimelineEntry;

/**
 * One waterfall row on the Request Detail timeline — the request root, a
 * lifecycle phase header, or a single correlated event. Precomputes the
 * bar geometry (percentages of the shared time scale) and the display
 * strings so the Blade side stays purely presentational.
 */
class TimelineRow extends Component
{
    /** Dot colour per event type shown in the pinned tree column; unknown types fall back to neutral. */
    protected const COLORS = [
        'query' => 'bg-amber-500 dark:bg-amber-400',
        'cache' => 'bg-emerald-500 dark:bg-emerald-400',
        'mail' => 'bg-pink-500 dark:bg-pink-400',
        'notification' => 'bg-fuchsia-500 dark:bg-fuchsia-400',
        'queue' => 'bg-orange-500 dark:bg-orange-400',
        'http' => 'bg-cyan-500 dark:bg-cyan-400',
    ];

    /** Inline badge text per event type; unknown types are uppercased. */
    protected const BADGES = [
        'query' => 'QUERY',
        'cache' => 'CACHE',
        'mail' => 'MAIL',
        'notification' => 'NOTIFICATION',
        'queue' => 'QUEUE',
        'http' => 'HTTP',
    ];

    public const ROOT_COLOR = 'bg-emerald-500/15 border border-emerald-500/40 dark:bg-emerald-400/10 dark:border-emerald-400/40';

    /** Nightwatch-style neutral event bar; only over-threshold events get a warning colour instead. */
    public const NEUTRAL_BAR = 'border border-neutral-200 bg-white group-hover:border-blue-400 dark:border-neutral-700 dark:bg-neutral-800 dark:group-hover:border-blue-500';

    public const SLOW_BAR = 'border border-amber-500 bg-amber-500/20 dark:border-amber-400 dark:bg-amber-400/20';

    /** Event types with their own inspector panel — everything else (root, phases, other event types) isn't clickable. */
    protected const DETAILABLE_TYPES = ['query', 'cache', 'mail', 'notification'];

    /** Bar left edge / width as percentages of the total duration. */
    public float $left;

    public float $width;

    public string $durationLabel;

    public string $badge;

    /** Secondary text (SQL, key, subject…), empty when redundant. */
    public string $detail;

    /** Detail clamped for the inline chart label. */
    public string $detailShort;

    /** Dot colour per event type, used only in the pinned tree column. */
    public string $color;

    /** Whether this event's duration is at/above the slow-event threshold — the only case the bar gets coloured. */
    public bool $slow;

    /** Bar background/border classes: neutral by default, warning colour when {@see $slow}. */
    public string $barColor;

    /** Whether clicking this row opens the inspector panel. */
    public bool $detailable;

    public string $rootColor = self::ROOT_COLOR;

    public function __construct(
        public TimelineEntry $entry,
        public int $total,
        public string $kind = 'event',
        /**
         * Which half of the two-pane layout this instance renders: the
         * pinned tree-column label ('label') or the horizontally-scrolling
         * chart bar ('bar'). The two panes are separate flex siblings (see
         * timeline.blade.php) rendered from the same $rows list, so each row
         * is built twice — once per pane — from this one component.
         */
        public string $part = 'bar',
        /** "REQUEST" for the Request Detail timeline, "JOB" for a job attempt's — see JobAttemptController. */
        public string $rootLabel = 'REQUEST',
    ) {
        $this->left = $total > 0 ? min(100, max(0, ($entry->start / $total) * 100)) : 0;
        $this->width = $total > 0 ? min(100 - $this->left, max(0.15, ($entry->duration / $total) * 100)) : 0.15;
        $this->durationLabel = Format::duration($entry->duration);
        $this->badge = self::BADGES[$entry->type] ?? strtoupper($entry->type);
        $this->color = self::COLORS[$entry->type] ?? 'bg-neutral-400 dark:bg-neutral-500';
        $this->slow = $kind === 'event' && $entry->duration >= (float) config('monitor.recorders.'.Queries::class.'.threshold', 100);
        $this->barColor = $this->slow ? self::SLOW_BAR : self::NEUTRAL_BAR;
        $this->detailable = $kind === 'event' && in_array($entry->type, self::DETAILABLE_TYPES, true);
        $this->detail = $this->resolveDetail();
        $this->detailShort = Str::limit($this->detail, 90);
    }

    public function render(): View
    {
        return view('monitor::components.requests.timeline-row');
    }

    /**
     * The most useful secondary text for the entry (the SQL for queries,
     * otherwise its label), dropping labels that merely repeat the type.
     */
    protected function resolveDetail(): string
    {
        $detail = trim((string) ($this->entry->metadata['sql'] ?? $this->entry->label));

        if (strcasecmp($detail, str_replace('_', ' ', $this->entry->type)) === 0
            || strcasecmp($detail, $this->badge) === 0) {
            return (string) ($this->entry->metadata['key'] ?? '');
        }

        return $detail;
    }
}
