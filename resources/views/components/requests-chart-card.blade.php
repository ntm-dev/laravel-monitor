{{-- REQUESTS card: total + 1/2/3XX / 4XX / 5XX legends over a stacked bar chart. --}}
@props(['count', 'ok', 'client', 'server', 'okBuckets', 'clientBuckets', 'serverBuckets', 'since', 'until', 'height' => 'h-28'])
<x-monitor::card :class="trim('flex flex-col p-4 '.($attributes->get('class') ?? ''))">
    <x-monitor::metric label="Requests" :value="number_format($count)">
        <x-monitor::legend label="1/2/3XX" dot="bg-neutral-300 dark:bg-neutral-600" :value="number_format($ok)"/>
        <x-monitor::legend label="4XX" dot="bg-amber-500" :value="number_format($client)" :color="$client > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-900 dark:text-neutral-100'"/>
        <x-monitor::legend label="5XX" dot="bg-rose-500" :value="number_format($server)" :color="$server > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-900 dark:text-neutral-100'"/>
    </x-monitor::metric>
    <div class="mt-5">
        <x-monitor::bar-chart :since="$since" :until="$until" :height="$height" :series="[
            ['label' => '1/2/3XX', 'dot' => 'bg-neutral-300 dark:bg-neutral-600', 'data' => $okBuckets],
            ['label' => '4XX', 'dot' => 'bg-amber-500', 'data' => $clientBuckets],
            ['label' => '5XX', 'dot' => 'bg-rose-500', 'data' => $serverBuckets],
        ]"/>
    </div>
    <x-monitor::chart-footer :since="$since" :until="$until"/>
</x-monitor::card>
