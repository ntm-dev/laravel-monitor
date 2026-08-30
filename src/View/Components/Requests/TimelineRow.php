<?php

namespace LaravelMonitor\View\Components\Requests;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use LaravelMonitor\ExecutionStage;
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
    /** Dot colour in the pinned tree column: exceptions, a duplicate-SQL group's colour, or the neutral default. */
    protected const DEFAULT_COLOR = 'border border-blue-500 dark:border-blue-400';

    protected const EXCEPTION_COLOR = 'border border-rose-500 bg-rose-500 dark:border-rose-400 dark:bg-rose-400';

    /** Dot colour for an outgoing HTTP call with a 4xx response — same filled style as {@see EXCEPTION_COLOR}, amber instead of rose. */
    protected const HTTP_WARNING_COLOR = 'border border-amber-500 bg-amber-500 dark:border-amber-400 dark:bg-amber-400';

    /** Dot colour for an outgoing HTTP call with a 2xx/3xx response — same filled style as {@see EXCEPTION_COLOR}, emerald instead of rose. */
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
        // No 'action' entry: that phase is shared by a request's controller
        // phase and a command's handle() phase (see ExecutionStage::Action),
        // so it's resolved dynamically in badgeFor() from the entry's own
        // label instead of a static lookup here.
    ];

    public const ROOT_COLOR = 'bg-emerald-500/15 border border-emerald-500/40 dark:bg-emerald-400/10 dark:border-emerald-400/40';

    /**
     * A job's own root bar — deliberately plain, not {@see ROOT_COLOR}'s
     * greenish tint, which reads as a status colour and would visually clash
     * with (or be mistaken for) the "processed" colour on its own 'attempt'
     * row directly underneath.
     */
    protected const NEUTRAL_ROOT_COLOR = 'border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-800';

    /**
     * Root/attempt bar colour by severity — an HTTP status for a request
     * root, a job outcome for an 'attempt' row (see {@see JOB_STATUS_SEVERITY}).
     * Keeps the same light background tint as ROOT_COLOR/warning; only the
     * border colour signals severity.
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

    /**
     * A job root's own severity bucket — same {@see STATUS_BADGE_COLORS}/
     * {@see ROOT_STATUS_COLORS} palette an HTTP status uses, keyed by the
     * job outcome's own subtype (see MergesJobTimelines::jobTrack()) instead
     * of a status code range.
     */
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

    /** Event types with their own inspector panel — everything else (root, phases, other event types) isn't clickable. */
    protected const DETAILABLE_TYPES = ['query', 'cache', 'mail', 'notification', 'lazy_loading', 'exception', 'http', 'queue'];

    public string $durationLabel;

    public string $badge;

    /** Secondary text (SQL, key, subject…), empty when redundant. */
    public string $detail;

    /** Detail clamped for the inline chart label. */
    public string $detailShort;

    /** Same as {@see $detail}, prefixed with the duplicate count — only ever shown in a hover tooltip, never inline. */
    public string $tooltipDetail;

    /** Dot colour used only in the pinned tree column: see {@see DEFAULT_COLOR}/{@see EXCEPTION_COLOR}/{@see HTTP_WARNING_COLOR}/{@see HTTP_SUCCESS_COLOR} — an HTTP event with any recorded response status fills the dot instead of leaving it hollow, even a 2xx/3xx one (unlike {@see $badgeTextColor}/{@see $barColor}, which only react to 4xx/5xx). */
    public string $color;

    /** Tailwind colour name (e.g. "emerald") if this entry belongs to a duplicate-SQL group, else null. Two unrelated groups can share this — see {@see $duplicateGroup} for the value that actually identifies one group uniquely. */
    public ?string $duplicateColor;

    /** Number of entries sharing this duplicate-SQL group, null when this entry isn't a duplicate. */
    public ?int $duplicateCount;

    /** This duplicate-SQL group's own key (normalized SQL shape), null when this entry isn't a duplicate — what selectRow() in timeline.blade.php actually matches on to pulse just this group's dots, since {@see $duplicateColor} alone can be shared by a different group. */
    public ?string $duplicateGroup;

    /** Badge text colour per event type, matching {@see $color}; neutral by default. An HTTP event with a 4xx/5xx response overrides this by status severity, same buckets as {@see $barColor} — a 2xx/3xx one doesn't (see {@see $color}'s own docs on why). */
    public string $badgeTextColor;

    /** Whether this event's duration is at/above the slow-event threshold — the only case the bar gets coloured (besides {@see $barColor}'s own HTTP-status override). */
    public bool $slow;

    /** Bar background/border classes: neutral by default, warning colour when {@see $slow}. An HTTP event's own 4xx/5xx response status takes priority over the duration-based warning when known, {@see TimelineRow::severity()} — since the status code is a more specific signal than "it was just slow"; a 2xx/3xx response doesn't tint the bar (see {@see $color}'s own docs). */
    public string $barColor;

    /** Whether clicking this row opens the inspector panel. */
    public bool $detailable;

    /** Whether clicking this row scrolls its bar to the center of the chart pane without opening the inspector — a phase header has no per-event detail to show, but is still worth jumping to directly. */
    public bool $scrollable;

    /** Bar background for a 'root' or 'attempt' row — neutral unless that row itself carries a status (an HTTP root's status code, or an attempt row's job outcome). */
    public string $rootColor;

    /** HTTP status of the root request, null for job/command roots (no status) or non-root rows. */
    public ?int $status = null;

    /** A job root's own status text (e.g. "PROCESSED"), null for every other kind of root or non-root row. */
    public ?string $statusLabel = null;

    /** Status badge background/text classes, empty when both {@see $status} and {@see $statusLabel} are null. */
    public string $statusBadgeClass = '';

    public function __construct(
        public TimelineEntry $entry,
        /** Bar left edge / width, as percentages of the page's one fixed time scale (see View\Components\Requests\Timeline) — precomputed there from this row's real wall-clock offset, not clamped to 0-100%, since a row from a non-default track can genuinely fall outside that window. */
        public float $left,
        public float $width,
        /** Which track (see View\Components\Requests\Timeline) this row belongs to — governs only its own expand/collapse visibility (`expandedTracks` in timeline.blade.php), not its position. */
        public string $trackId,
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
        /** Whether clicking this root row toggles its own track's expand state — false when there's only one track (nothing to toggle). */
        public bool $focusable = false,
        /** This row's own track's attempt number (see MergesJobTimelines::jobTrack()) — null for a request/command/scheduled-task track, which has no attempt concept. */
        public ?int $attempt = null,
        /** This row's own track's job outcome status ('processed'/'failed'/'released' — see MergesJobTimelines::jobTrack()), null for every other kind of track. */
        public ?string $jobStatus = null,
        /** Sum of every attempt's own duration for a job track's root row (see MergesJobTimelines::jobTrack()) — shown instead of {@see $entry}'s own duration, which is that root's bounding-box span across every attempt (including idle retry-wait time) rather than how long the job actually ran for. Null for every other kind of row. */
        public ?int $attemptsDuration = null,
        /** A job track's own root row's url (see View\Components\Requests\Timeline's $jobBaseUrl) — clicking it navigates there instead of merely toggling the track's own expand state, since arriving there already expands (and scales the page around) this exact job. Null for every other kind of row, and for a job track's root when the current page has nowhere of that kind to navigate to. */
        public ?string $jobUrl = null,
    ) {
        $this->durationLabel = $attemptsDuration !== null
            ? Format::duration($attemptsDuration)
            : ($entry->duration !== null ? Format::duration($entry->duration) : '');
        $this->badge = self::badgeFor($entry->type, $entry->label);
        $this->duplicateColor = $entry->metadata['duplicateColor'] ?? null;
        $this->duplicateCount = $entry->metadata['duplicateCount'] ?? null;
        $this->duplicateGroup = $entry->metadata['duplicateGroup'] ?? null;
        // An outgoing HTTP call's own response status, same severity() bucket
        // the request root uses (error/warning/ok, coloured rose/amber/
        // emerald below) — null only when the call has no recorded status
        // at all (e.g. the connection itself failed), left neutral rather
        // than treated as an error: a genuine response, even a bad one, is
        // a more specific, more actionable signal than "no response".
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
        // 'ok' deliberately isn't its own branch here (unlike $color above):
        // a 2xx/3xx response only tints the dot, not the badge text — an
        // all-emerald row read as loudly as an all-rose one, when success is
        // the common case that shouldn't compete for attention with actual
        // problems (4xx/5xx).
        $this->badgeTextColor = match ($httpSeverity) {
            'error' => 'text-rose-600 dark:text-rose-400',
            'warning' => 'text-amber-600 dark:text-amber-400',
            default => self::BADGE_TEXT_COLORS[$entry->type] ?? 'text-neutral-700 dark:text-neutral-200',
        };
        // Per-type "slow" signal against the same monitor.thresholds.* values
        // the Settings page edits (see Support\Settings) — comparing every
        // event's duration against the query recorder's own threshold
        // (regardless of type) used to mark multi-second commands/jobs/
        // notifications as "slow" right alongside queries that hadn't even
        // crossed the *query* threshold the user configured (recorder's
        // fast/slow subtype tag is a separate, .env-only threshold used for
        // the Slow Queries digest, not what Settings/the dashboard call
        // "query threshold"). queue/http map to their own threshold keys;
        // every other type (cache, mail, notification, lazy_loading, nested
        // commands/scheduled tasks) has no configured "slow" concept at all,
        // so it never gets the warning bar.
        $this->slow = $kind === 'event' && match ($entry->type) {
            'query' => $entry->duration >= (float) config('monitor.thresholds.query', 500),
            'queue' => $entry->duration >= (float) config('monitor.thresholds.job', 1000),
            'http' => $entry->duration >= (float) config('monitor.thresholds.outgoing_request', 1000),
            default => false,
        };
        // Same restraint as $badgeTextColor above: 'ok' doesn't tint the bar
        // either, only the dot — see that comment.
        $this->barColor = match (true) {
            $entry->type === 'exception' => self::EXCEPTION_BAR,
            $httpSeverity === 'error' => self::EXCEPTION_BAR,
            $httpSeverity === 'warning' => self::SLOW_BAR,
            $this->slow => self::SLOW_BAR,
            default => self::NEUTRAL_BAR,
        };
        $this->detailable = $kind === 'event' && in_array($entry->type, self::DETAILABLE_TYPES, true);
        $this->scrollable = $kind === 'phase';
        $this->detail = $this->resolveDetail();
        $this->detailShort = Str::limit($this->detail, 90);
        $this->tooltipDetail = $this->duplicateCount !== null
            ? "Called {$this->duplicateCount} " . Str::plural('time', $this->duplicateCount) . " — {$this->detail}"
            : $this->detail;
        // A job root ('root' kind, badge "JOB") is just the attempt(s)'
        // identity/duration and deliberately stays neutral — the outcome
        // status (processed/failed/released) belongs on its own 'attempt'
        // row underneath instead (see Timeline::__construct()), one per
        // execution, each coloured independently. An HTTP request root is
        // the only kind that still colours itself, by status code.
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
     *
     * @param  ?string  $label  the entry's own precomputed label — only
     *                          needed to disambiguate the Action phase,
     *                          which reads "CONTROLLER" on a request root
     *                          and "HANDLE" on a command root (see
     *                          Support\Timeline::phaseEntries()); every
     *                          other type's badge is static.
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
