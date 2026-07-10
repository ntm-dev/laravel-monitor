{{-- JOBS card: total + FAILED / PROCESSED / QUEUED legends over a stacked bar chart. --}}
@props(['queued', 'processed', 'failed', 'queuedBuckets', 'processedBuckets', 'failedBuckets', 'since', 'until', 'size' => 'lg', 'height' => 'h-28', 'footer' => true])
<x-monitor::card :class="trim('flex flex-col p-4 '.($attributes->get('class') ?? ''))">
    <x-monitor::metric label="Jobs" :value="number_format($queued + $processed + $failed)" :size="$size">
        <x-monitor::legend label="Failed" dot="bg-rose-500" :value="number_format($failed)" :color="$failed > 0 ? 'text-rose-600' : 'text-neutral-900'" :size="$size"/>
        <x-monitor::legend label="Processed" dot="bg-neutral-300" :value="number_format($processed)" :size="$size"/>
        <x-monitor::legend label="Queued" dot="bg-amber-500" :value="number_format($queued)" :size="$size"/>
    </x-monitor::metric>
    <div class="{{ $size === 'lg' ? 'mt-5' : 'mt-4' }}">
        <x-monitor::bar-chart :since="$since" :until="$until" :height="$height" :series="[
            ['label' => 'Processed', 'dot' => 'bg-neutral-300', 'data' => $processedBuckets],
            ['label' => 'Queued', 'dot' => 'bg-amber-500', 'data' => $queuedBuckets],
            ['label' => 'Failed', 'dot' => 'bg-rose-500', 'data' => $failedBuckets],
        ]"/>
    </div>
    @if ($footer)
        <x-monitor::chart-footer :since="$since" :until="$until"/>
    @endif
</x-monitor::card>
