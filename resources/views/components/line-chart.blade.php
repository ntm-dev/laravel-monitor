{{-- AVG / P95 duration line chart with Nightwatch-style dark tooltips. --}}
@props(['avg', 'p95', 'since', 'until', 'height' => 'h-28', 'threshold' => null])
@php
    $lineFmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $lineBuckets = max(1, count($avg));
    $lineSeconds = max(1, (int) ($since->diffInSeconds($until) / $lineBuckets));
    $lineMax = 0.000001;
    foreach ([$avg, $p95] as $lineData) {
        foreach ($lineData as $lineValue) {
            if ($lineValue !== null) {
                $lineMax = max($lineMax, $lineValue);
            }
        }
    }
    if ($threshold !== null) {
        $lineMax = max($lineMax, $threshold);
    }
    $lineSegments = function (array $lineData) use ($lineMax) {
        $segments = [];
        $current = [];
        foreach ($lineData as $lineI => $lineValue) {
            if ($lineValue === null) {
                if ($current !== []) {
                    $segments[] = $current;
                    $current = [];
                }
                continue;
            }
            $current[] = [$lineI + 0.5, round(97 - ($lineValue / $lineMax) * 90, 2)];
        }
        if ($current !== []) {
            $segments[] = $current;
        }
        return $segments;
    };
    $lineY = fn ($v) => round(97 - ($v / $lineMax) * 90, 2);
    $lineTz = \LaravelMonitor\Support\Format::timezone();
@endphp
<div class="relative {{ $height }}">
    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
        @for ($lineI = 0; $lineI < 5; $lineI++)
            <div class="border-t border-neutral-100"></div>
        @endfor
    </div>
    <svg class="absolute inset-0 h-full w-full" viewBox="0 0 {{ $lineBuckets }} 100" preserveAspectRatio="none">
        @if ($threshold !== null)
            <line x1="0" x2="{{ $lineBuckets }}" y1="{{ round(97 - ($threshold / $lineMax) * 90, 2) }}" y2="{{ round(97 - ($threshold / $lineMax) * 90, 2) }}"
                  stroke="#2dd4bf" stroke-width="1" stroke-dasharray="2 3" vector-effect="non-scaling-stroke"/>
        @endif
        @foreach ([['data' => $p95, 'color' => '#f59e0b'], ['data' => $avg, 'color' => '#404040']] as $lineSerie)
            @foreach ($lineSegments($lineSerie['data']) as $lineSegment)
                @if (count($lineSegment) === 1)
                    <circle cx="{{ $lineSegment[0][0] }}" cy="{{ $lineSegment[0][1] }}" r="1.5" fill="{{ $lineSerie['color'] }}" vector-effect="non-scaling-stroke"/>
                @else
                    <polyline points="{{ collect($lineSegment)->map(fn ($p) => $p[0].','.$p[1])->implode(' ') }}"
                              fill="none" stroke="{{ $lineSerie['color'] }}" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                @endif
            @endforeach
        @endforeach
        @for ($lineI = 0; $lineI < $lineBuckets; $lineI++)
            @if (($avg[$lineI] ?? null) !== null)
                <circle cx="{{ $lineI + 0.5 }}" cy="{{ $lineY($avg[$lineI]) }}" r="2" fill="#404040" stroke="#fff" stroke-width="1"
                        x-show="hoverIndex === {{ $lineI }}" x-cloak vector-effect="non-scaling-stroke"/>
            @endif
            @if (($p95[$lineI] ?? null) !== null)
                <circle cx="{{ $lineI + 0.5 }}" cy="{{ $lineY($p95[$lineI]) }}" r="2" fill="#f59e0b" stroke="#fff" stroke-width="1"
                        x-show="hoverIndex === {{ $lineI }}" x-cloak vector-effect="non-scaling-stroke"/>
            @endif
        @endfor
    </svg>
    <div class="pointer-events-none absolute inset-y-0 z-10 w-px bg-neutral-300"
         x-show="hoverIndex !== null" x-cloak
         :style="'left: ' + (((hoverIndex ?? 0) + 0.5) / {{ $lineBuckets }} * 100) + '%'"></div>
    <div class="absolute inset-0 flex" @mouseleave="clearHoverIndex()">
        @for ($lineI = 0; $lineI < $lineBuckets; $lineI++)
            <div class="relative h-full flex-1" :class="{ 'bg-neutral-100/60': hoverIndex === {{ $lineI }} }"
                 @mouseenter="setHoverIndex({{ $lineI }})">
                <div class="pointer-events-none absolute bottom-full {{ $lineI < $lineBuckets / 2 ? 'left-0' : 'right-0' }} z-20 mb-2 w-56 rounded-lg bg-neutral-900 p-3 shadow-xl shadow-black/20"
                     x-show="hoverIndex === {{ $lineI }}" x-cloak>
                    <p class="font-mono text-[11px] text-neutral-200">{{ \LaravelMonitor\Support\Format::datetime($since->copy()->addSeconds($lineI * $lineSeconds)) }} <span class="text-neutral-500">{{ $lineTz }}</span></p>
                    <div class="mt-2 space-y-1.5 border-t border-neutral-700/60 pt-2">
                        <p class="flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-tight text-neutral-400">
                            <span class="inline-block h-2.5 w-1 rounded-full bg-neutral-600"></span>Avg
                            <span class="ml-auto text-neutral-100">{{ $lineFmt($avg[$lineI] ?? null) }}</span>
                        </p>
                        <p class="flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-tight text-neutral-400">
                            <span class="inline-block h-2.5 w-1 rounded-full bg-amber-500"></span>P95
                            <span class="ml-auto text-neutral-100">{{ $lineFmt($p95[$lineI] ?? null) }}</span>
                        </p>
                    </div>
                </div>
            </div>
        @endfor
    </div>
</div>
