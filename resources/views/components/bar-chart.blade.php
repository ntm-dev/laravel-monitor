{{-- Stacked bar chart with Nightwatch-style dark tooltips.
     $series = [['label', 'dot' (pill class), 'bar' (segment class), 'data' => int[]], ...] --}}
@props(['series', 'since', 'until', 'height' => 'h-28'])
@php
    $chartBuckets = count($series[0]['data'] ?? []) ?: 1;
    $chartSeconds = max(1, (int) ($since->diffInSeconds($until) / $chartBuckets));
    $chartTotals = [];
    for ($chartI = 0; $chartI < $chartBuckets; $chartI++) {
        $chartTotals[$chartI] = 0;
        foreach ($series as $chartSerie) {
            $chartTotals[$chartI] += $chartSerie['data'][$chartI] ?? 0;
        }
    }
    $chartMax = max(1, max($chartTotals ?: [0]));
    $chartTz = \LaravelMonitor\Support\Format::timezone();
@endphp
<div class="relative {{ $height }}">
    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
        @for ($chartI = 0; $chartI < 5; $chartI++)
            <div class="border-t border-neutral-100"></div>
        @endfor
    </div>
    <div class="pointer-events-none absolute inset-y-0 z-10 w-px bg-neutral-300"
         x-show="hoverIndex !== null" x-cloak
         :style="'left: ' + (((hoverIndex ?? 0) + 0.5) / {{ $chartBuckets }} * 100) + '%'"></div>
    <div class="relative flex h-full items-end gap-px" @mouseleave="clearHoverIndex()">
        @for ($chartI = 0; $chartI < $chartBuckets; $chartI++)
            <div class="relative flex h-full flex-1 flex-col justify-end"
                 :class="{ 'bg-neutral-100/60': hoverIndex === {{ $chartI }} }"
                 @mouseenter="setHoverIndex({{ $chartI }})">
                @foreach (array_reverse($series) as $chartSerie)
                    @php($chartValue = $chartSerie['data'][$chartI] ?? 0)
                    @if ($chartValue > 0)
                        <div class="w-full first:rounded-t-[2px] {{ $chartSerie['bar'] ?? $chartSerie['dot'] }}"
                             style="height: {{ max(2, $chartValue / $chartMax * 100) }}%"></div>
                    @endif
                @endforeach
                @if (($chartTotals[$chartI] ?? 0) === 0)
                    <div class="h-[2px] w-full bg-neutral-200/70"></div>
                @endif
                <div class="pointer-events-none absolute bottom-full {{ $chartI < $chartBuckets / 2 ? 'left-0' : 'right-0' }} z-20 mb-2 w-56 rounded-lg bg-neutral-900 p-3 shadow-xl shadow-black/20"
                     x-show="hoverIndex === {{ $chartI }}" x-cloak>
                    <p class="font-mono text-[11px] text-neutral-200">{{ \LaravelMonitor\Support\Format::datetime($since->copy()->addSeconds($chartI * $chartSeconds)) }} <span class="text-neutral-500">{{ $chartTz }}</span></p>
                    <div class="mt-2 space-y-1.5 border-t border-neutral-700/60 pt-2">
                        @foreach ($series as $chartSerie)
                            <p class="flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-tight text-neutral-400">
                                <span class="inline-block h-2.5 w-1 rounded-full {{ $chartSerie['dot'] }}"></span>{{ $chartSerie['label'] }}
                                <span class="ml-auto text-neutral-100">{{ number_format($chartSerie['data'][$chartI] ?? 0) }}</span>
                            </p>
                        @endforeach
                        <p class="flex items-center gap-1.5 border-t border-neutral-700/60 pt-1.5 font-mono text-[11px] uppercase tracking-tight text-neutral-400">Total<span class="ml-auto text-neutral-100">{{ number_format($chartTotals[$chartI] ?? 0) }}</span></p>
                    </div>
                </div>
            </div>
        @endfor
    </div>
</div>
