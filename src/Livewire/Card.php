<?php

namespace LaravelMonitor\Livewire;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use LaravelMonitor\Contracts\AggregateStorage;
use LaravelMonitor\Contracts\CacheAndQueryStorage;
use LaravelMonitor\Contracts\ExceptionStorage;
use LaravelMonitor\Contracts\HashResolver;
use LaravelMonitor\Contracts\IssueStorage;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Contracts\UserStorage;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Preferences;
use LaravelMonitor\Support\StorageTime;
use Livewire\Component;
use Throwable;

abstract class Card extends Component
{
    public const DEFAULT_PERIOD = '1h';

    /**
     * Candidate chart bucket widths (seconds), finest first; bucketSeconds()
     * picks the finest one within MAX_CHART_BUCKETS (1h -> 30s, 24h -> 15m,
     * 7d -> 2h, 14d -> 4h, 30d -> 6h).
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
     * Manual refresh from x-monitor::refresh-button. Empty on purpose: it only
     * differs from the auto-refresh's $refresh so loading skeletons can use
     * `wire:target.except="$refresh"` and stay hidden while polling.
     */
    public function refreshNow(): void
    {
        //
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
     * Inputs are naive wall-clock strings in the viewer's Preferences::timezone().
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

        // Aligned to the bucket grid so bucket boundaries stay fixed between
        // polls instead of drifting with every recomputed now().
        $hours = self::periods()[$this->period] ?? 1;
        $bucketSeconds = $this->bucketSeconds();
        $timestamp = CarbonImmutable::now()->subHours($hours)->getTimestamp();

        return StorageTime::fromTimestamp((int) floor($timestamp / $bucketSeconds) * $bucketSeconds);
    }

    /**
     * Width of the selected window (custom range or preset), in seconds.
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
     * Number of chart buckets for the current window.
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
     * Parse a Format::RANGE boundary in the viewer's timezone, converted to
     * config('app.timezone'): stored `created_at` values are naive to it, and
     * SQL bindings format a date in whatever timezone it carries.
     */
    protected function parseCustomBoundary(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, Preferences::timezone())
            ->setTimezone(config('app.timezone', 'UTC'));
    }

    /**
     * Human phrase for the selected range, e.g. "in the last 24 hours".
     * Carbon's locale is set explicitly since Laravel never syncs it.
     */
    public function periodPhrase(): string
    {
        if ($this->hasCustomRange()) {
            return __('monitor::messages.common.period_phrase_custom');
        }

        $hours = self::periods()[$this->period] ?? 1;

        // Days from 48h up (24h stays "24 hours"); weeks skipped ("30 days").
        $interval = $hours >= 48 && $hours % 24 === 0
            ? CarbonInterval::days(intdiv($hours, 24))
            : CarbonInterval::hours($hours);

        return __('monitor::messages.common.period_phrase', [
            'duration' => $interval->locale(app()->getLocale())->forHumans(['skip' => ['week']]),
        ]);
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

    /**
     * One accessor per Storage sub-contract, so a card only autoloads the
     * storage classes it actually calls.
     */
    protected function aggregateStorage(): AggregateStorage
    {
        return app(AggregateStorage::class);
    }

    protected function timelineStorage(): TimelineStorage
    {
        return app(TimelineStorage::class);
    }

    protected function userStorage(): UserStorage
    {
        return app(UserStorage::class);
    }

    protected function cacheAndQueryStorage(): CacheAndQueryStorage
    {
        return app(CacheAndQueryStorage::class);
    }

    protected function exceptionStorage(): ExceptionStorage
    {
        return app(ExceptionStorage::class);
    }

    protected function issueStorage(): IssueStorage
    {
        return app(IssueStorage::class);
    }

    protected function hashResolver(): HashResolver
    {
        return app(HashResolver::class);
    }

    /**
     * Pop a toast in the shared stack (components/toast-container.blade.php).
     *
     * @param  'primary'|'secondary'|'success'|'danger'|'warning'|'info'  $level
     */
    protected function notify(string $level, string $message): void
    {
        $this->dispatch('toast', level: $level, message: $message);
    }
}
