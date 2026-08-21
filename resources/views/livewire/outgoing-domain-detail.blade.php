@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();
    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
         x-data="{
             hoverIndex: null,
             setHoverIndex(i) { this.hoverIndex = i },
             clearHoverIndex() { this.hoverIndex = null },
         }">
        <x-monitor::requests-chart-card
            :count="$stats->count" :ok="$okRequests" :client="$clientErrors" :server="$serverErrors"
            :ok-buckets="$okBuckets" :client-buckets="$clientErrorBuckets" :server-buckets="$serverErrorBuckets"
            :since="$since" :until="$until" height="h-[167px]"/>
        <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" :threshold="$threshold" height="h-[167px]"/>
    </div>

    {{-- Individual requests --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="Icons::OUTGOING" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalEntries) }} {{ trans_choice('monitor::messages.common.request_count', $totalEntries) }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_individual_requests_recorded_in_period') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.date') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.source') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.method') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.status') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.url') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.duration') }}</th>
                            </tr>
                        </thead>
                        <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($entries as $entry)
                                {{-- request_id is a generic correlation id (request/job/command/scheduled task) —
                                     sourceType/sourceLabel/sourceUrl (set in OutgoingDomainDetail::data()) resolve it
                                     to the right detail page instead of assuming every call came from an HTTP request. --}}
                                @php($status = $entry->payload['status'] ?? null)
                                @php($method = $entry->payload['method'] ?? null)
                                @php($methodClass = match ($method) {
                                    'POST' => 'text-emerald-600',
                                    'PUT', 'PATCH' => 'text-blue-500',
                                    'DELETE' => 'text-rose-600 dark:text-rose-400',
                                    default => 'text-neutral-500 dark:text-neutral-400',
                                })
                                <tr class="group hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                    <td class="{{ $entry->sourceUrl ? 'cursor-pointer' : '' }} max-w-[16rem] py-2 pr-3" @if ($entry->sourceUrl) onclick="window.location='{{ $entry->sourceUrl }}'" @endif>
                                        <x-monitor::exception-source-badge :type="$entry->sourceType" :label="$entry->sourceLabel" :url="$entry->sourceUrl"/>
                                    </td>
                                    <td class="py-2 pr-3 font-mono text-xs uppercase tracking-tight {{ $methodClass }}">{{ $method ?? '—' }}</td>
                                    <td class="py-2 pr-3">
                                        @if ($status !== null && $status >= 500)
                                            <span class="inline-flex items-center rounded-md border border-rose-600 bg-rose-600 px-1.5 py-0.5 font-mono text-xs font-medium text-white dark:border-rose-500 dark:bg-rose-600">{{ $status }}</span>
                                        @else
                                            <span class="font-mono text-xs font-medium {{ match (true) {
                                                    $status === null => 'text-neutral-400 dark:text-neutral-500',
                                                    $status >= 400 => 'text-amber-600 dark:text-amber-400',
                                                    default => 'text-emerald-600 dark:text-emerald-400',
                                                } }}">{{ $status ?? __('monitor::messages.common.failed') }}</span>
                                        @endif
                                    </td>
                                    <td class="max-w-[20rem] py-2 pr-3" title="{{ $entry->payload['url'] ?? $entry->key }}">
                                        <span class="flex items-center gap-1.5">
                                            <x-monitor::icon :path="Icons::REQUESTS" :stroke="1.8" class="h-3.5 w-3.5 shrink-0 text-neutral-400 dark:text-neutral-500 group-hover:text-blue-600 dark:group-hover:text-blue-400"/>
                                            <span class="truncate font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $entry->payload['url'] ?? $entry->key }}</span>
                                        </span>
                                    </td>
                                    <td class="py-2 text-right font-mono text-xs {{ ($entry->duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($entry->duration) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-100 dark:divide-neutral-800">
                            <x-monitor::table-skeleton :columns="6" :rows="count($entries)"/>
                        </tbody>
                    </table>
                </div>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalEntries), 'total' => number_format($totalEntries)])"/>
                @endif
            @endif
        </x-monitor::card>
    </div>
</div>
