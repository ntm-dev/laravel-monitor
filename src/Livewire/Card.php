<?php

namespace LaravelMonitor\Livewire;

use Carbon\CarbonImmutable;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Format;
use Livewire\Component;
use Throwable;

abstract class Card extends Component
{
    public const DEFAULT_PERIOD = '1h';

    /**
     * Time slices used by the bar / line charts.
     */
    public const CHART_BUCKETS = 60;

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
     * @return array{0: string|null, 1: string|null}
     */
    public static function normalizeRange(?string $from, ?string $to): array
    {
        if (blank($from) || blank($to)) {
            return [null, null];
        }

        try {
            $fromDate = CarbonImmutable::parse($from);
            $toDate = CarbonImmutable::parse($to);
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
            return CarbonImmutable::parse($this->from);
        }

        return CarbonImmutable::now()->subHours(self::periods()[$this->period] ?? 1);
    }

    /**
     * Upper bound of the selected range; null means "now" (live presets).
     */
    protected function until(): ?CarbonImmutable
    {
        return $this->hasCustomRange() ? CarbonImmutable::parse($this->to) : null;
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
