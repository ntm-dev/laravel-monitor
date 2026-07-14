{{-- AVG / P95 duration line chart with Nightwatch-style dark tooltips.
     All geometry is precomputed by LaravelMonitor\View\Components\LineChart so lines,
     standalone dots and hover markers share the exact same coordinates. --}}
<div class="relative {{ $height }}" x-data="{ lineHoverY: {{ \Illuminate\Support\Js::from($hoverY) }} }">
    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
        @for ($i = 0; $i < 5; $i++)
            <div class="border-t border-neutral-100 dark:border-neutral-800"></div>
        @endfor
    </div>
    <svg class="absolute inset-0 h-full w-full" viewBox="0 0 {{ $buckets }} 100" preserveAspectRatio="none">
        @if ($thresholdY !== null)
            <line x1="0" x2="{{ $buckets }}" y1="{{ $thresholdY }}" y2="{{ $thresholdY }}"
                  stroke="#2dd4bf" stroke-width="0.1" stroke-dasharray="2 3" vector-effect="non-scaling-stroke"/>
        @endif
        @foreach ($series as $serie)
            @foreach ($serie['lines'] as $points)
                <polyline points="{{ $points }}" fill="none" stroke="{{ $serie['color'] }}"
                          stroke-width="{{ \LaravelMonitor\View\Components\LineChart::STROKE_WIDTH }}"
                          stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
            @endforeach
        @endforeach
    </svg>
    {{-- Isolated data points render as HTML dots so the non-uniform SVG scale can't distort them. --}}
    @foreach ($series as $serie)
        @foreach ($serie['dots'] as $dot)
            <div class="pointer-events-none absolute rounded-full"
                 style="width: {{ \LaravelMonitor\View\Components\LineChart::DOT_DIAMETER }}px; height: {{ \LaravelMonitor\View\Components\LineChart::DOT_DIAMETER }}px; transform: translate(-50%, -50%); left: {{ $dot['x'] }}%; top: {{ $dot['y'] }}%; background: {{ $serie['color'] }}"></div>
        @endforeach
    @endforeach
    <div class="pointer-events-none absolute inset-y-0 z-10 w-px bg-neutral-300 dark:bg-neutral-600"
         x-show="hoverIndex !== null" x-cloak
         :style="{ left: (((hoverIndex ?? 0) + 0.5) / {{ $buckets }} * 100) + '%' }"></div>
    {{-- Hover markers reuse the polyline coordinates (lineHoverY); hidden when the hovered bucket has no data. --}}
    <div class="pointer-events-none absolute z-10 rounded-full"
         style="width: {{ \LaravelMonitor\View\Components\LineChart::DOT_DIAMETER }}px; height: {{ \LaravelMonitor\View\Components\LineChart::DOT_DIAMETER }}px; transform: translate(-50%, -50%); background: #f59e0b"
         x-show="hoverIndex !== null && lineHoverY.p95[hoverIndex] !== null" x-cloak
         :style="{ left: (((hoverIndex ?? 0) + 0.5) / {{ $buckets }} * 100) + '%', top: (lineHoverY.p95[hoverIndex ?? 0] ?? 0) + '%' }"></div>
    <div class="pointer-events-none absolute z-10 rounded-full"
         style="width: {{ \LaravelMonitor\View\Components\LineChart::DOT_DIAMETER }}px; height: {{ \LaravelMonitor\View\Components\LineChart::DOT_DIAMETER }}px; transform: translate(-50%, -50%); background: #404040"
         x-show="hoverIndex !== null && lineHoverY.avg[hoverIndex] !== null" x-cloak
         :style="{ left: (((hoverIndex ?? 0) + 0.5) / {{ $buckets }} * 100) + '%', top: (lineHoverY.avg[hoverIndex ?? 0] ?? 0) + '%' }"></div>
    <div class="absolute inset-0 flex" @mouseleave="clearHoverIndex()">
        @foreach ($tooltips as $i => $tooltip)
            <div class="relative h-full flex-1"
                 @if ($tooltip['hasData']) :class="{ 'bg-neutral-100/60 dark:bg-neutral-800/60': hoverIndex === {{ $i }} }" @endif
                 @mouseenter="setHoverIndex({{ $i }})">
                <div class="pointer-events-none absolute bottom-full {{ $tooltip['anchor'] }} z-20 mb-2 w-56 rounded-lg bg-neutral-900 p-3 shadow-xl shadow-black/20"
                     x-show="hoverIndex === {{ $i }}" x-cloak>
                    <p class="font-mono text-[11px] text-neutral-200">{{ $tooltip['time'] }} <span class="text-neutral-500">{{ $timezone }}</span></p>
                    <div class="mt-2 space-y-1.5 border-t border-neutral-700/60 pt-2">
                        <p class="flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-tight text-neutral-400">
                            <span class="inline-block h-2.5 w-1 rounded-full bg-neutral-600"></span>Avg
                            <span class="ml-auto text-neutral-100">{{ $tooltip['avg'] }}</span>
                        </p>
                        <p class="flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-tight text-neutral-400">
                            <span class="inline-block h-2.5 w-1 rounded-full bg-amber-500"></span>P95
                            <span class="ml-auto text-neutral-100">{{ $tooltip['p95'] }}</span>
                        </p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
