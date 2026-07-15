{{-- Generic Cache metric card: total headline + per-series legends over a
     stacked bar chart. Reused for both the Events breakdown
     (hits/misses/writes/deletes) and the Failures breakdown
     (write/delete failures) on the Cache page.
     $series = [['label', 'dot', 'total' => int, 'data' => int[]], ...] --}}
@props(['label', 'total', 'series', 'since', 'until', 'height' => 'h-28'])
<x-monitor::card {{ $attributes->merge(['class' => 'flex flex-col p-4']) }}>
    <x-monitor::metric :label="$label" :value="number_format($total)">
        @foreach ($series as $s)
            <x-monitor::legend :label="$s['label']" :dot="$s['dot']" :value="number_format($s['total'])"
                                :color="($s['total'] ?? 0) > 0 && ($s['warn'] ?? false) ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-900 dark:text-neutral-100'"/>
        @endforeach
    </x-monitor::metric>
    <div class="mt-5">
        <x-monitor::bar-chart :since="$since" :until="$until" :height="$height" :series="$series"/>
    </div>
    <x-monitor::chart-footer :since="$since" :until="$until"/>
</x-monitor::card>
