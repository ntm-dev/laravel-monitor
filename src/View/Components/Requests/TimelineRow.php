<?php

namespace LaravelMonitor\View\Components\Requests;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;
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
    /** Bar colour per event type; unknown types fall back to neutral. */
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

    /** Bar left edge / width as percentages of the total duration. */
    public float $left;

    public float $width;

    /** Left edge of the inline label rendered right after an event bar. */
    public float $labelLeft;

    public string $durationLabel;

    public string $badge;

    /** Secondary text (SQL, key, subject…), empty when redundant. */
    public string $detail;

    /** Detail clamped for the inline chart label. */
    public string $detailShort;

    public string $color;

    public string $rootColor = self::ROOT_COLOR;

    public function __construct(
        public TimelineEntry $entry,
        public int $total,
        public string $kind = 'event',
    ) {
        $this->left = $total > 0 ? min(100, max(0, ($entry->start / $total) * 100)) : 0;
        $this->width = $total > 0 ? min(100 - $this->left, max(0.15, ($entry->duration / $total) * 100)) : 0.15;
        $this->labelLeft = min(100, $this->left + $this->width);
        $this->durationLabel = Format::duration($entry->duration);
        $this->badge = self::BADGES[$entry->type] ?? strtoupper($entry->type);
        $this->color = self::COLORS[$entry->type] ?? 'bg-neutral-400 dark:bg-neutral-500';
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
