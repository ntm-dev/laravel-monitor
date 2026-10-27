<?php

namespace LaravelMonitor\View\Components\Requests;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use LaravelMonitor\ExecutionStage;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\TimelineEntry;

/**
 * One waterfall row on a timeline (root, attempt, phase header or event).
 * Precomputes bar geometry and display strings for the Blade view.
 */
class TimelineRow extends Component
{
    /** Tree-column dot colours; see {@see $color}. */
    protected const DEFAULT_COLOR = 'border border-blue-500 dark:border-blue-400';

    protected const EXCEPTION_COLOR = 'border border-rose-500 bg-rose-500 dark:border-rose-400 dark:bg-rose-400';

    /** Outgoing HTTP call with a 4xx response. */
    protected const HTTP_WARNING_COLOR = 'border border-amber-500 bg-amber-500 dark:border-amber-400 dark:bg-amber-400';

    /** Outgoing HTTP call with a 2xx/3xx response. */
    protected const HTTP_SUCCESS_COLOR = 'border border-emerald-500 bg-emerald-500 dark:border-emerald-400 dark:bg-emerald-400';

    /** Inline badge text per event type; unknown types are uppercased. */
    protected const BADGES = [
        'query' => 'QUERY',
        'cache' => 'CACHE',
        'mail' => 'MAIL SENT',
        'notification' => 'NOTIFICATION SENT',
        'queue' => 'JOB DISPATCH',
        'http' => 'HTTP',
        'lazy_loading' => 'N+1',
        'command' => 'COMMAND',
        // No 'action' entry: badgeFor() derives it from the entry's label
        // (controller for a request, handle for a command).
    ];

    public const ROOT_COLOR = 'bg-emerald-500/15 border border-emerald-500/40 dark:bg-emerald-400/10 dark:border-emerald-400/40';

    /** A job's root bar: plain, so it isn't mistaken for the status colour of the attempt rows beneath it. */
    protected const NEUTRAL_ROOT_COLOR = 'border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-800';

    /** Root/attempt bar colour by severity: HTTP status for a request root, job outcome for an attempt row. */
    protected const ROOT_STATUS_COLORS = [
        'error' => 'bg-rose-600/15 border border-rose-600 dark:bg-rose-400/10 dark:border-rose-600',
        'warning' => 'bg-amber-500/15 border border-amber-500/40 dark:bg-amber-400/10 dark:border-amber-400/40',
    ];

    /** Status badge colours: solid fill matching the severity colour, white text. */
    protected const STATUS_BADGE_COLORS = [
        'error' => 'bg-rose-600 dark:bg-rose-600 text-white',
        'warning' => 'bg-amber-700 dark:bg-amber-400 text-white',
        'ok' => 'bg-emerald-700 dark:bg-emerald-400 text-white',
    ];

    /** Severity bucket for an attempt row's job outcome (see MergesJobTimelines::jobTrack()). */
    protected const JOB_STATUS_SEVERITY = [
        'failed' => 'error',
        'released' => 'warning',
        'processed' => 'ok',
    ];

    /** Neutral default event bar; only over-threshold events get a warning colour instead. */
    public const NEUTRAL_BAR = 'border border-neutral-200 bg-white group-hover:border-blue-400 dark:border-neutral-700 dark:bg-neutral-800 dark:group-hover:border-blue-500';

    public const SLOW_BAR = 'border border-amber-500 bg-amber-500/20 dark:border-amber-400 dark:bg-amber-400/20';

    /** Exception bars are always rose-600, regardless of duration. */
    public const EXCEPTION_BAR = 'border border-rose-600 bg-rose-600/20 dark:border-rose-600 dark:bg-rose-600/20';

    /** Badge text colour per event type; unknown types fall back to neutral. */
    protected const BADGE_TEXT_COLORS = [
        'exception' => 'text-rose-600 dark:text-rose-600',
    ];

    /** Event types with their own section in the inspector panel; phase/attempt rows open it regardless. */
    protected const DETAILABLE_TYPES = ['query', 'cache', 'mail', 'notification', 'lazy_loading', 'exception', 'http', 'queue'];

    public string $durationLabel;

    public string $badge;

    /** Secondary text (SQL, key, subject…), empty when redundant. */
    public string $detail;

    /** Detail clamped for the inline chart label. */
    public string $detailShort;

    /** {@see $detail} prefixed with the duplicate count, for the hover tooltip. */
    public string $tooltipDetail;

    /** Tree-column dot colour. Any HTTP response status fills it, even 2xx/3xx. */
    public string $color;

    /** Tailwind colour name of this entry's duplicate-SQL group, else null; not unique per group. */
    public ?string $duplicateColor;

    /** Number of entries sharing this duplicate-SQL group, null when this entry isn't a duplicate. */
    public ?int $duplicateCount;

    /** Unique key of this duplicate-SQL group (normalized SQL), else null; selectRow() pulses by it. */
    public ?string $duplicateGroup;

    /** Badge text colour; an HTTP 4xx/5xx response overrides the per-type default. */
    public string $badgeTextColor;

    /** Whether the duration is at/above this event type's configured threshold. */
    public bool $slow;

    /** Bar classes: an HTTP 4xx/5xx status wins over {@see $slow}; otherwise neutral. */
    public string $barColor;

    /** Whether clicking this row opens the inspector panel. */
    public bool $detailable;

    /** Whether clicking this row centers its bar without opening the inspector (phase headers only). */
    public bool $scrollable;

    /** Bar background for a root or attempt row, tinted by its own status when it has one. */
    public string $rootColor;

    /** HTTP status of the root request, null for job/command roots (no status) or non-root rows. */
    public ?int $status = null;

    /** A job root's own status text (e.g. "PROCESSED"), null for every other kind of root or non-root row. */
    public ?string $statusLabel = null;

    /** Status badge background/text classes, empty when both {@see $status} and {@see $statusLabel} are null. */
    public string $statusBadgeClass = '';

    public function __construct(
        public TimelineEntry $entry,
        /** Bar left edge / width as percentages of the page's time scale; not clamped to 0-100. */
        public float $left,
        public float $width,
        /** Owning track; drives only expand/collapse visibility (`expandedTracks`). */
        public string $trackId,
        public string $kind = 'event',
        /** Pane this instance renders: the pinned tree label ('label') or the chart bar ('bar'). */
        public string $part = 'bar',
        /** Root row label: the track's badge (e.g. REQUEST, JOB). */
        public string $rootLabel = 'REQUEST',
        /** Whether clicking this root row toggles its track; false with a single track. */
        public bool $focusable = false,
        /** Attempt number for an attempt row, else null. */
        public ?int $attempt = null,
        /** Job outcome ('processed'/'failed'/'released') for an attempt row, else null. */
        public ?string $jobStatus = null,
        /** Job root only: sum of attempt durations, shown instead of the bounding-box span (which includes retry waits). */
        public ?float $attemptsDuration = null,
        /** Job root only: URL of this page focused on that job; null when there is none. */
        public ?string $jobUrl = null,
    ) {
        $this->durationLabel = $attemptsDuration !== null
            ? Format::duration($attemptsDuration)
            : ($entry->duration !== null ? Format::duration($entry->duration) : '');
        $this->badge = self::badgeFor($entry->type, $entry->label);
        $this->duplicateColor = $entry->metadata['duplicateColor'] ?? null;
        $this->duplicateCount = $entry->metadata['duplicateCount'] ?? null;
        $this->duplicateGroup = $entry->metadata['duplicateGroup'] ?? null;
        // Null when the HTTP call has no recorded status (e.g. connection failed): left neutral.
        $httpSeverity = $kind === 'event' && $entry->type === 'http' && isset($entry->metadata['status'])
            ? self::severity((int) $entry->metadata['status'])
            : null;
        $this->color = match (true) {
            $entry->type === 'exception' => self::EXCEPTION_COLOR,
            $httpSeverity === 'error' => self::EXCEPTION_COLOR,
            $httpSeverity === 'warning' => self::HTTP_WARNING_COLOR,
            $httpSeverity === 'ok' => self::HTTP_SUCCESS_COLOR,
            $this->duplicateColor !== null => "border border-{$this->duplicateColor}-500 bg-{$this->duplicateColor}-500 dark:border-{$this->duplicateColor}-400 dark:bg-{$this->duplicateColor}-400",
            default => self::DEFAULT_COLOR,
        };
        // A 2xx/3xx response tints only the dot, not the badge text or bar.
        $this->badgeTextColor = match ($httpSeverity) {
            'error' => 'text-rose-600 dark:text-rose-400',
            'warning' => 'text-amber-600 dark:text-amber-400',
            default => self::BADGE_TEXT_COLORS[$entry->type] ?? 'text-neutral-700 dark:text-neutral-200',
        };
        // Per-type monitor.thresholds.* (editable in Settings); other types are never slow.
        $this->slow = $kind === 'event' && match ($entry->type) {
            'query' => $entry->duration >= (float) config('monitor.thresholds.query', 500),
            'queue' => $entry->duration >= (float) config('monitor.thresholds.job', 1000),
            'http' => $entry->duration >= (float) config('monitor.thresholds.outgoing_request', 1000),
            default => false,
        };
        $this->barColor = match (true) {
            $entry->type === 'exception' => self::EXCEPTION_BAR,
            $httpSeverity === 'error' => self::EXCEPTION_BAR,
            $httpSeverity === 'warning' => self::SLOW_BAR,
            $this->slow => self::SLOW_BAR,
            default => self::NEUTRAL_BAR,
        };
        // Phase/attempt rows open the panel too, for their precise started_at/ended_at.
        $this->detailable = ($kind === 'event' && in_array($entry->type, self::DETAILABLE_TYPES, true))
            || $kind === 'phase' || $kind === 'attempt';
        $this->scrollable = $kind === 'phase';
        $this->detail = $this->resolveDetail();
        $this->detailShort = Str::limit($this->detail, 90);
        $this->tooltipDetail = $this->duplicateCount !== null
            ? "Called {$this->duplicateCount} " . Str::plural('time', $this->duplicateCount) . " — {$this->detail}"
            : $this->detail;
        // A job root stays neutral; each attempt row carries its own outcome colour.
        $severity = null;

        if ($kind === 'root' && isset($entry->metadata['status'])) {
            $this->status = (int) $entry->metadata['status'];
            $severity = self::severity($this->status);
        } elseif ($kind === 'attempt' && $jobStatus !== null) {
            $this->statusLabel = strtoupper($jobStatus);
            $severity = self::JOB_STATUS_SEVERITY[$jobStatus] ?? 'ok';
        }

        if ($severity !== null) {
            $this->statusBadgeClass = self::STATUS_BADGE_COLORS[$severity];
        }

        $this->rootColor = match (true) {
            $severity !== null => self::ROOT_STATUS_COLORS[$severity] ?? self::ROOT_COLOR,
            $kind === 'root' && $rootLabel === 'JOB' => self::NEUTRAL_ROOT_COLOR,
            default => self::ROOT_COLOR,
        };
    }

    /** HTTP status severity bucket: error for 5xx, warning for 4xx, ok otherwise. */
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
     * Inline badge text for an event type; also used by Timeline for the inspector panel.
     *
     * @param  ?string  $label  only used for the Action phase (CONTROLLER / HANDLE)
     */
    public static function badgeFor(string $type, ?string $label = null): string
    {
        if ($type === ExecutionStage::Action->value && $label !== null) {
            return strtoupper($label);
        }

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
