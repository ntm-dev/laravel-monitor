<?php

namespace LaravelMonitor\Livewire;

use Carbon\CarbonImmutable;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Preferences;
use Livewire\Component;
use Throwable;

abstract class Card extends Component
{
    public const DEFAULT_PERIOD = '1h';

    /**
     * Candidate bucket widths (seconds), finest first — mirrors Nightwatch's
     * own per-period chart resolution (1h -> 30s buckets, 24h -> 15m, 7d ->
     * 2h, 14d -> 4h): the chart uses the *finest* width here that still
     * keeps the total point count at or under MAX_CHART_BUCKETS, instead of
     * always slicing every range into the same fixed number of buckets
     * (which made a 30-day chart exactly as coarse per-pixel as a 1-hour
     * one). Each width is chosen so it divides evenly into every period in
     * config('monitor.periods') — see bucketSeconds().
     */
    protected const NICE_BUCKET_SECONDS = [
        30, 60, 300, 600, 900, 1800, 3600, 7200, 14400, 21600, 43200, 86400, 172800, 259200, 604800,
    ];

    protected const MAX_CHART_BUCKETS = 120;

    public string $period = self::DEFAULT_PERIOD;

    /**
     * Custom range boundaries in Format::RANGE (minute precision).
     * When both are set they take precedence over $period.
     */
    public ?string $from = null;

    public ?string $to = null;

    public int $limit = 10;

    public function mount(?string $period = null, ?string $from = null, ?string $to = null): void
    {
        $period ??= request('period', self::DEFAULT_PERIOD);

        $this->period = array_key_exists($period, self::periods()) ? $period : self::DEFAULT_PERIOD;

        [$this->from, $this->to] = self::normalizeRange(
            $from ?? request('from'),
            $to ?? request('to'),
        );
    }

    /**
     * Preset ranges (key => hours) from the package config.
     *
     * @return array<string, int>
     */
    public static function periods(): array
    {
        return config('monitor.periods', [self::DEFAULT_PERIOD => 1]);
    }

    /**
     * Validate a custom from/to pair: both parseable, chronological, and
     * never reaching into the future. Returns [null, null] when invalid.
     *
     * The <input type="datetime-local"> values arrive as naive wall-clock
     * strings the viewer picked in their own Preferences::timezone(), not
     * the app's default timezone — parsing them without that timezone would
     * silently reinterpret e.g. "14:00" typed in Asia/Ho_Chi_Minh as 14:00
     * UTC, shifting the whole queried window by the viewer's UTC offset.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function normalizeRange(?string $from, ?string $to): array
    {
        if (blank($from) || blank($to)) {
            return [null, null];
        }

        try {
            $fromDate = CarbonImmutable::parse($from, Preferences::timezone());
            $toDate = CarbonImmutable::parse($to, Preferences::timezone());
        } catch (Throwable) {
            return [null, null];
        }

        $toDate = $toDate->min(CarbonImmutable::now());

        if ($fromDate >= $toDate) {
            return [null, null];
        }

        return [$fromDate->format(Format::RANGE), $toDate->format(Format::RANGE)];
    }

    protected function hasCustomRange(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    protected function since(): CarbonImmutable
    {
        if ($this->hasCustomRange()) {
            return $this->parseCustomBoundary($this->from);
        }

        // Rounded down to the chart's bucket-width grid, not a bare
        // now()->subHours(): a live window recomputes "since" fresh on
        // every poll, so an unaligned since drifts forward by however many
        // seconds elapsed since the last poll — shifting every bucket's
        // absolute boundary by that same amount and reshuffling which
        // bucket a row near an edge falls into, even when no new data has
        // arrived. Aligning to the grid means since only jumps forward in
        // whole bucket-width steps, so the chart's layout stays fixed
        // between polls that land inside the same step.
        $hours = self::periods()[$this->period] ?? 1;
        $bucketSeconds = $this->bucketSeconds();
        $timestamp = CarbonImmutable::now()->subHours($hours)->getTimestamp();

        return CarbonImmutable::createFromTimestamp((int) floor($timestamp / $bucketSeconds) * $bucketSeconds);
    }

    /**
     * Total width of the selected window, in seconds — the selected range
     * for a custom period, or the preset's configured hours for a live one.
     */
    protected function windowSeconds(): int
    {
        if ($this->hasCustomRange()) {
            return max(1, $this->parseCustomBoundary($this->from)->diffInSeconds($this->parseCustomBoundary($this->to)));
        }

        return (int) ((self::periods()[$this->period] ?? 1) * 3600);
    }

    /**
     * The finest width from NICE_BUCKET_SECONDS that still keeps the chart
     * to at most MAX_CHART_BUCKETS points for the current window.
     */
    protected function bucketSeconds(): int
    {
        $totalSeconds = $this->windowSeconds();

        foreach (self::NICE_BUCKET_SECONDS as $width) {
            if ((int) ceil($totalSeconds / $width) <= self::MAX_CHART_BUCKETS) {
                return $width;
            }
        }

        return self::NICE_BUCKET_SECONDS[count(self::NICE_BUCKET_SECONDS) - 1];
    }

    /**
     * Number of time slices the bar / line charts should render for the
     * current period or custom range — see bucketSeconds().
     */
    public function chartBuckets(): int
    {
        return max(1, (int) ceil($this->windowSeconds() / $this->bucketSeconds()));
    }

    /**
     * Upper bound of the selected range; null means "now" (live presets).
     */
    protected function until(): ?CarbonImmutable
    {
        return $this->hasCustomRange() ? $this->parseCustomBoundary($this->to) : null;
    }

    /**
     * Parse a Format::RANGE boundary (from/to) as wall-clock time in the
     * viewer's Preferences::timezone(), then normalize it to the app's own
     * timezone. Storage always deals in `created_at` values naive to
     * config('app.timezone') (see DatabaseStorage::store()/hydrate()) — a
     * Carbon instance still tagged with the viewer's timezone would print
     * the wrong wall-clock string once it reaches a SQL binding, since
     * Illuminate\Database\Connection::prepareBindings() formats a
     * DateTimeInterface value as-is, in whatever timezone it's carrying.
     */
    protected function parseCustomBoundary(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, Preferences::timezone())
            ->setTimezone(config('app.timezone', 'UTC'));
    }

    /**
     * Human phrase describing the selected range, e.g. "in the last 24 hours".
     */
    public function periodPhrase(): string
    {
        if ($this->hasCustomRange()) {
            return 'in the selected range';
        }

        $hours = self::periods()[$this->period] ?? 1;

        return 'in the last '.match (true) {
            $hours === 1 => 'hour',
            $hours % 24 === 0 && $hours >= 48 => ($hours / 24).' days',
            default => $hours.' hours',
        };
    }

    /**
     * The Blade view backing this card.
     */
    abstract protected function view(): string;

    /**
     * Card-specific view data. Range variables are added automatically.
     *
     * @return array<string, mixed>
     */
    abstract protected function data(): array;

    public function render()
    {
        return view($this->view(), $this->data() + [
            'since' => $this->since(),
            'until' => $this->until() ?? CarbonImmutable::now(),
            'periodPhrase' => $this->periodPhrase(),
            // Query-string state to carry through dashboard links.
            'range' => array_filter(['period' => $this->period, 'from' => $this->from, 'to' => $this->to]),
            'refresh' => (int) config('monitor.refresh', 10),
        ]);
    }

    protected function storage(): Storage
    {
        return app(Storage::class);
    }
}
