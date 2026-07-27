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
    /** Solid fill used for the exception dot in the tree pane. */
    protected const ERROR_COLOR = 'bg-rose-600 dark:border-rose-500 dark:bg-rose-600';

    /** Dot colour per event type shown in the pinned tree column; unknown types fall back to neutral. */
    protected const COLORS = [
        'query' => 'bg-amber-500 dark:bg-amber-400',
        'cache' => 'bg-emerald-500 dark:bg-emerald-400',
        'mail' => 'bg-pink-500 dark:bg-pink-400',
        'notification' => 'bg-fuchsia-500 dark:bg-fuchsia-400',
        'queue' => 'bg-orange-500 dark:bg-orange-400',
        'http' => 'bg-cyan-500 dark:bg-cyan-400',
        'lazy_loading' => 'bg-rose-500 dark:bg-rose-400',
        'exception' => self::ERROR_COLOR,
    ];

    /** Inline badge text per event type; unknown types are uppercased. */
    protected const BADGES = [
        'query' => 'QUERY',
        'cache' => 'CACHE',
        'mail' => 'MAIL SENT',
        'notification' => 'NOTIFICATION SENT',
        'queue' => 'QUEUE',
        'http' => 'HTTP',
        'lazy_loading' => 'N+1',
    ];

    public const ROOT_COLOR = 'bg-emerald-500/15 border border-emerald-500/40 dark:bg-emerald-400/10 dark:border-emerald-400/40';

    /**
     * Root bar colour by HTTP status severity — job/command roots have no
     * status and keep {@see ROOT_COLOR}. Keeps the same light background
     * tint as ROOT_COLOR/warning; only the border colour signals severity.
     */
    protected const ROOT_STATUS_COLORS = [
        'error' => 'bg-rose-600/15 border border-rose-600 dark:bg-rose-400/10 dark:border-rose-600',
        'warning' => 'bg-amber-500/15 border border-amber-500/40 dark:bg-amber-400/10 dark:border-amber-400/40',
    ];

    /** Status badge colours: solid fill matching the severity colour, white text. */
    protected const STATUS_BADGE_COLORS = [
        'error' => 'bg-rose-600 dark:bg-rose-600 text-white', //dark:border-rose-500 dark:bg-rose-600
        'warning' => 'bg-amber-700 dark:bg-amber-400 text-white',
        'ok' => 'bg-emerald-700 dark:bg-emerald-400 text-white',
    ];

    /** Nightwatch-style neutral event bar; only over-threshold events get a warning colour instead. */
    public const NEUTRAL_BAR = 'border border-neutral-200 bg-white group-hover:border-blue-400 dark:border-neutral-700 dark:bg-neutral-800 dark:group-hover:border-blue-500';

    public const SLOW_BAR = 'border border-amber-500 bg-amber-500/20 dark:border-amber-400 dark:bg-amber-400/20';

    /** Exception bars are always rose-600, regardless of duration. */
    public const EXCEPTION_BAR = 'border border-rose-600 bg-rose-600/20 dark:border-rose-600 dark:bg-rose-600/20';

    /** Badge text colour per event type; unknown types fall back to neutral. */
    protected const BADGE_TEXT_COLORS = [
        'exception' => 'text-rose-600 dark:text-rose-600',
    ];

    /** Event types with their own inspector panel — everything else (root, phases, other event types) isn't clickable. */
    protected const DETAILABLE_TYPES = ['query', 'cache', 'mail', 'notification', 'lazy_loading', 'exception'];

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

    /** Badge text colour per event type, matching {@see $color}; neutral by default. */
    public string $badgeTextColor;

    /** Whether this event's duration is at/above the slow-event threshold — the only case the bar gets coloured. */
    public bool $slow;

    /** Bar background/border classes: neutral by default, warning colour when {@see $slow}. */
    public string $barColor;

    /** Whether clicking this row opens the inspector panel. */
    public bool $detailable;

    public string $rootColor;

    /** HTTP status of the root request, null for job/command roots (no status) or non-root rows. */
    public ?int $status = null;

    /** Status badge background/text classes, empty when {@see $status} is null. */
    public string $statusBadgeClass = '';

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
        $this->badge = self::badgeFor($entry->type);
        $this->color = self::COLORS[$entry->type] ?? 'bg-neutral-400 dark:bg-neutral-500';
        $this->badgeTextColor = self::BADGE_TEXT_COLORS[$entry->type] ?? 'text-neutral-700 dark:text-neutral-200';
        $this->slow = $kind === 'event' && $entry->duration >= (float) config('monitor.recorders.'.Queries::class.'.threshold', 100);
        $this->barColor = match (true) {
            $entry->type === 'exception' => self::EXCEPTION_BAR,
            $this->slow => self::SLOW_BAR,
            default => self::NEUTRAL_BAR,
        };
        $this->detailable = $kind === 'event' && in_array($entry->type, self::DETAILABLE_TYPES, true);
        $this->detail = $this->resolveDetail();
        $this->detailShort = Str::limit($this->detail, 90);
        if ($kind === 'root' && isset($entry->metadata['status'])) {
            $this->status = (int) $entry->metadata['status'];
            $this->statusBadgeClass = self::STATUS_BADGE_COLORS[self::severity($this->status)];
        }

        $this->rootColor = $this->status !== null
            ? (self::ROOT_STATUS_COLORS[self::severity($this->status)] ?? self::ROOT_COLOR)
            : self::ROOT_COLOR;
    }

    /**
     * HTTP status severity bucket, matching the badge colours used
     * elsewhere (header.blade.php, request-detail.blade.php's list): rose
     * for 5xx, amber for 4xx, the default green otherwise.
     */
    protected static function severity(int $status): string
    {
        return match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'ok',
        };
    }

    public function render(): View
    {
        return view('monitor::components.requests.timeline-row');
    }

    /**
     * The inline badge text for an event type — shared with
     * View\Components\Requests\Timeline (the JSON entry map read by the
     * Alpine inspector panel), so the panel header shows the same wording
     * as the tree/bar rows instead of a second, JS-side mapping drifting
     * out of sync with this one.
     */
    public static function badgeFor(string $type): string
    {
        return self::BADGES[$type] ?? strtoupper($type);
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
