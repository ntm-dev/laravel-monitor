{{-- EXCEPTIONS card: total occurrences + handled / unhandled legends over a stacked bar chart. --}}
@props(['count', 'handled', 'unhandled', 'handledBuckets', 'unhandledBuckets', 'since', 'until', 'height' => 'h-28', 'label' => 'Occurrences'])
<x-monitor::card :class="trim('flex flex-col p-4 '.($attributes->get('class') ?? ''))">
    <x-monitor::metric :label="$label" :value="number_format($count)">
        <x-monitor::legend label="Handled" dot="bg-neutral-300" :value="number_format($handled)"/>
        <x-monitor::legend label="Unhandled" dot="bg-rose-500" :value="number_format($unhandled)" :color="$unhandled > 0 ? 'text-rose-600' : 'text-neutral-900'"/>
    </x-monitor::metric>
    <div class="mt-5">
        <x-monitor::bar-chart :since="$since" :until="$until" :height="$height" :series="[
            ['label' => 'Unhandled', 'dot' => 'bg-rose-500', 'data' => $unhandledBuckets],
            ['label' => 'Handled', 'dot' => 'bg-neutral-300', 'data' => $handledBuckets],
        ]"/>
    </div>
    <x-monitor::chart-footer :since="$since" :until="$until"/>
</x-monitor::card>
