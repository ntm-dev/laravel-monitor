<?php

namespace LaravelMonitor\View\Components;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use LaravelMonitor\Support\Format;

/**
 * AVG / P95 duration line chart.
 *
 * Values are projected into a 0-100 viewBox whose y coordinate doubles as a CSS
 * percentage, so polylines (SVG), standalone dots and hover markers (HTML) all
 * share the exact same coordinates.
 */
class LineChart extends Component
{
    /** Line stroke width in px; dots are kept at 3x this diameter. */
    public const STROKE_WIDTH = 1.5;

    public const DOT_DIAMETER = self::STROKE_WIDTH * 3;

    public int $buckets;

    public string $timezone;

    public ?float $thresholdY = null;

    /**
     * Series painted on the chart, p95 first so avg draws on top where they overlap.
     * `lines` holds one polyline point string per run of >=2 consecutive buckets with
     * data; buckets whose neighbours are both empty become standalone `dots` instead.
     *
     * @var list<array{color: string, lines: list<string>, dots: list<array{x: float, y: float}>}>
     */
    public array $series = [];

    /**
     * Per-series y position (percent) per bucket, null when the bucket has no data.
     * The Alpine hover markers read these so they sit exactly on the polylines.
     *
     * @var array{p95: list<float|null>, avg: list<float|null>}
     */
    public array $hoverY = [];

    /**
     * Tooltip content per bucket: timestamp label, formatted avg / p95, whether the
     * bucket holds any data, and which side the tooltip anchors to.
     *
     * @var list<array{time: string, avg: string, p95: string, hasData: bool, anchor: string}>
     */
    public array $tooltips = [];

    private float $max = 0.000001;

    public function __construct(
        public array $avg,
        public array $p95,
        public CarbonInterface $since,
        public CarbonInterface $until,
        public string $height = 'h-28',
        public int|float|null $threshold = null,
    ) {
        $this->buckets = max(1, count($avg));
        $this->timezone = Format::timezone();

        foreach ([...$avg, ...$p95, $threshold] as $value) {
            if ($value !== null) {
                $this->max = max($this->max, $value);
            }
        }

        $this->thresholdY = $threshold === null ? null : $this->y($threshold);

        foreach ([['p95', '#f59e0b', $p95], ['avg', '#404040', $avg]] as [$name, $color, $data]) {
            $this->series[] = [
                'color' => $color,
                'lines' => $this->lines($data),
                'dots' => $this->isolatedDots($data),
            ];
            $this->hoverY[$name] = array_map(fn ($value) => $value === null ? null : $this->y($value), $data);
        }

        $bucketSeconds = max(1, (int) ($since->diffInSeconds($until) / $this->buckets));

        for ($i = 0; $i < $this->buckets; $i++) {
            $this->tooltips[] = [
                'time' => Format::datetime($since->copy()->addSeconds($i * $bucketSeconds)),
                'avg' => Format::duration($avg[$i] ?? null),
                'p95' => Format::duration($p95[$i] ?? null),
                'hasData' => ($avg[$i] ?? null) !== null || ($p95[$i] ?? null) !== null,
                'anchor' => $i < $this->buckets / 2 ? 'left-0' : 'right-0',
            ];
        }
    }

    public function render(): View
    {
        return view('monitor::components.line-chart');
    }

    /** Horizontal center of a bucket as a percentage of the chart width. */
    public function x(int $bucket): float
    {
        return round(($bucket + 0.5) / $this->buckets * 100, 3);
    }

    /** Project a duration onto the 0-100 viewBox (97 = baseline, 7 = max). */
    private function y(int|float $value): float
    {
        return round(97 - ($value / $this->max) * 90, 2);
    }

    /** Polyline point strings for each run of consecutive buckets with data. */
    private function lines(array $data): array
    {
        $runs = [[]];

        foreach ($data as $i => $value) {
            if ($value === null) {
                $runs[] = [];
            } else {
                $runs[array_key_last($runs)][] = ($i + 0.5).','.$this->y($value);
            }
        }

        return array_values(array_map(
            fn (array $run) => implode(' ', $run),
            array_filter($runs, fn (array $run) => count($run) > 1),
        ));
    }

    /**
     * Buckets holding data while both neighbours are empty: rendered as standalone
     * dots instead of being joined to non-adjacent points by a line.
     */
    private function isolatedDots(array $data): array
    {
        $dots = [];

        foreach ($data as $i => $value) {
            if ($value !== null && ($data[$i - 1] ?? null) === null && ($data[$i + 1] ?? null) === null) {
                $dots[] = ['x' => $this->x($i), 'y' => $this->y($value)];
            }
        }

        return $dots;
    }
}
