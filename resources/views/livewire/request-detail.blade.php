@php
    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $tz = \LaravelMonitor\Support\Format::timezone();
    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2"
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
        <div class="flex flex-wrap items-center justify-between gap-2 px-1 pb-3">
            <div class="flex items-center gap-2">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::REQUESTS" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
                <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalEntries) }} {{ trans_choice('monitor::messages.common.request_count', $totalEntries) }}</h2>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex h-8 items-center gap-0.5 rounded-lg border border-neutral-200 bg-neutral-200/40 p-0.5 text-xs dark:border-neutral-700/50 dark:bg-neutral-800">
                    @foreach ([
                        'all' => __('monitor::messages.common.view_all'),
                        'avg' => '≥ '.__('monitor::messages.common.avg'),
                        'p95' => '≥ '.__('monitor::messages.common.p95'),
                        'threshold' => '≥ '.__('monitor::messages.common.threshold'),
                    ] as $filterKey => $filterLabel)
                        <button type="button" wire:click="setDurationFilter('{{ $filterKey }}')"
                                wire:loading.attr="disabled" wire:target="setDurationFilter('{{ $filterKey }}')"
                                @class([
                                    'flex h-full items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 transition-colors',
                                    'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-neutral-100' => $durationFilter === $filterKey,
                                    'text-neutral-600 hover:bg-neutral-200/20 dark:text-neutral-400 dark:hover:bg-neutral-900/20' => $durationFilter !== $filterKey,
                                ])>
                            {{ $filterLabel }}
                            <span class="rounded bg-neutral-200/80 dark:bg-neutral-700/80 px-1.5 font-mono text-[10px] text-neutral-600 dark:text-neutral-300">{{ $durationFilterCounts[$filterKey] }}</span>
                            <svg wire:loading wire:target="setDurationFilter('{{ $filterKey }}')" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
                            </svg>
                        </button>
                    @endforeach
                </div>
                <div class="flex h-8 items-center gap-0.5 rounded-lg border border-neutral-200 bg-neutral-200/40 p-0.5 text-xs dark:border-neutral-700/50 dark:bg-neutral-800">
                    @foreach ([
                        'all' => __('monitor::messages.common.view_all'),
                        'ok' => __('monitor::messages.common.ok_status'),
                        '4xx' => __('monitor::messages.common.client_error'),
                        '5xx' => __('monitor::messages.common.server_error'),
                    ] as $filterKey => $filterLabel)
                        <button type="button" wire:click="setStatusFilter('{{ $filterKey }}')"
                                wire:loading.attr="disabled" wire:target="setStatusFilter('{{ $filterKey }}')"
                                @class([
                                    'flex h-full items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 transition-colors',
                                    'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-neutral-100' => $statusFilter === $filterKey,
                                    'text-neutral-600 hover:bg-neutral-200/20 dark:text-neutral-400 dark:hover:bg-neutral-900/20' => $statusFilter !== $filterKey,
                                ])>
                            {{ $filterLabel }}
                            <span class="rounded bg-neutral-200/80 dark:bg-neutral-700/80 px-1.5 font-mono text-[10px] text-neutral-600 dark:text-neutral-300">{{ $statusFilterCounts[$filterKey] }}</span>
                            <svg wire:loading wire:target="setStatusFilter('{{ $filterKey }}')" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
                            </svg>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_individual_requests_recorded_in_period') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.date') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.method') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.details') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.status') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.duration') }}</th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage,setDurationFilter,setStatusFilter" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($entries as $entry)
                            {{-- One combined "php" block, not three consecutive ones: Blade's
                                 compiler mis-merges 3+ adjacent inline php blocks (verified via
                                 the compiled view — the 1st and 2nd got swallowed into the
                                 3rd's raw-PHP extraction, so $status/$detailUrl never
                                 actually ran and every row 500'd on "Undefined variable
                                 $detailUrl"). --}}
                            @php
                                $status = (int) ($entry->payload['status'] ?? 0);
                                $detailUrl = ($entry->request_id ?? null) ? route('monitor.requests.routes.request', ['hash' => \LaravelMonitor\Support\KeyHash::for($key), 'requestId' => $entry->request_id]) : null;
                            @endphp
                            <tr @if ($detailUrl) onclick="window.location='{{ $detailUrl }}'" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @else class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @endif>
                                <td class="py-2 pr-3 font-mono text-xs">
                                    @if ($detailUrl)
                                        <a href="{{ $detailUrl }}" class="text-blue-600 hover:underline dark:text-blue-400" onclick="event.stopPropagation()">{{ \LaravelMonitor\Support\Format::datetime($entry->created_at) }}</a>
                                    @else
                                        <span class="text-neutral-700 dark:text-neutral-200">{{ \LaravelMonitor\Support\Format::datetime($entry->created_at) }}</span>
                                    @endif
                                    <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span>
                                </td>
                                <td class="py-2 pr-3 font-mono text-xs uppercase tracking-tight {{ \LaravelMonitor\Support\Format::httpMethodClass($entry->payload['method'] ?? null) }}">{{ $entry->payload['method'] ?? '—' }}</td>
                                <td class="max-w-[18rem] truncate py-2 pr-3 font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $entry->payload['path'] ?? '—' }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $status >= 500 ? 'text-rose-600 dark:text-rose-400' : ($status >= 400 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400') }}">{{ $status ?: '—' }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($entry->duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($entry->duration) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage,setDurationFilter,setStatusFilter" class="hidden animate-pulse divide-y divide-neutral-100 dark:divide-neutral-800">
                        <x-monitor::table-skeleton :columns="5" :rows="count($entries)"/>
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalEntries), 'total' => number_format($totalEntries)])"/>
                @endif
            @endif
        </x-monitor::card>
    </div>
</div>
