{{-- DURATION card: min-max headline + AVG / P95 legends over a line chart. --}}
@props(['duration', 'since', 'until', 'label' => 'Duration', 'threshold' => null, 'size' => 'lg', 'height' => 'h-28', 'footer' => true])
@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<x-monitor::card :class="trim('flex flex-col p-4 '.($attributes->get('class') ?? ''))">
    <x-monitor::metric :label="$label" :value="$duration->min !== null ? $fmt($duration->min).' - '.$fmt($duration->max) : '—'" :size="$size">
        @if ($threshold !== null)
            <x-monitor::legend label="Threshold" dot="bg-teal-400" :value="$fmt($threshold)" :size="$size"/>
        @endif
        <x-monitor::legend label="Avg" dot="bg-neutral-800" :value="$fmt($duration->avg)" :size="$size"/>
        <x-monitor::legend label="P95" dot="bg-amber-500" :value="$fmt($duration->p95)" :size="$size"/>
    </x-monitor::metric>
    <div class="{{ $size === 'lg' ? 'mt-5' : 'mt-4' }}">
        <x-monitor::line-chart :avg="$duration->avg_per_bucket" :p95="$duration->p95_per_bucket" :since="$since" :until="$until" :height="$height" :threshold="$threshold"/>
    </div>
    @if ($footer)
        <x-monitor::chart-footer :since="$since" :until="$until"/>
    @endif
</x-monitor::card>
