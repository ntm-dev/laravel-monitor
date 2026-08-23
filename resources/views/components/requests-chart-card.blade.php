{{-- REQUESTS card: total + 1/2/3XX / 4XX / 5XX (+ optional Failed) legends over a stacked bar chart.
     failed/failedBuckets are omitted by every caller except Outgoing Requests — a connection
     failure never reached a server, so it's tracked as its own group rather than folded into 5XX. --}}
@props(['count', 'ok', 'client', 'server', 'okBuckets', 'clientBuckets', 'serverBuckets', 'since', 'until', 'height' => 'h-28', 'failed' => null, 'failedBuckets' => null])
<x-monitor::card :class="trim('flex flex-col p-4 '.($attributes->get('class') ?? ''))">
    <x-monitor::metric :label="__('monitor::messages.nav.requests')" :value="number_format($count)">
        <x-monitor::legend :label="__('monitor::messages.common.ok_status')" dot="bg-neutral-300 dark:bg-neutral-600" :value="number_format($ok)"/>
        <x-monitor::legend :label="__('monitor::messages.common.client_error')" dot="bg-amber-500" :value="number_format($client)" :color="$client > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-900 dark:text-neutral-100'"/>
        <x-monitor::legend :label="__('monitor::messages.common.server_error')" dot="bg-rose-500" :value="number_format($server)" :color="$server > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-900 dark:text-neutral-100'"/>
        @if ($failed !== null)
            <x-monitor::legend :label="__('monitor::messages.common.failed')" dot="bg-violet-500" :value="number_format($failed)" :color="$failed > 0 ? 'text-violet-600 dark:text-violet-400' : 'text-neutral-900 dark:text-neutral-100'"/>
        @endif
    </x-monitor::metric>
    <div class="mt-5">
        <x-monitor::bar-chart :since="$since" :until="$until" :height="$height" :series="[
            ['label' => __('monitor::messages.common.ok_status'), 'dot' => 'bg-neutral-300 dark:bg-neutral-600', 'data' => $okBuckets],
            ['label' => __('monitor::messages.common.client_error'), 'dot' => 'bg-amber-500', 'data' => $clientBuckets],
            ['label' => __('monitor::messages.common.server_error'), 'dot' => 'bg-rose-500', 'data' => $serverBuckets],
            ...($failedBuckets !== null ? [['label' => __('monitor::messages.common.failed'), 'dot' => 'bg-violet-500', 'data' => $failedBuckets]] : []),
        ]"/>
    </div>
    <x-monitor::chart-footer :since="$since" :until="$until"/>
</x-monitor::card>
