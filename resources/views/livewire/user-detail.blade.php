@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\KeyHash;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();
    $from = ($page - 1) * $perPage;

    $filters = [
        ['tab' => 'requests', 'label' => __('monitor::messages.nav.requests')],
        ['tab' => 'jobs', 'label' => __('monitor::messages.nav.jobs')],
        ['tab' => 'exceptions', 'label' => __('monitor::messages.nav.exceptions')],
        ['tab' => 'logs', 'label' => __('monitor::messages.nav.logs')],
    ];
@endphp
<div wire:poll.{{ $refresh }}s>

    <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
         x-data="{
             hoverIndex: null,
             setHoverIndex(i) { this.hoverIndex = i },
             clearHoverIndex() { this.hoverIndex = null },
         }">
        {{-- Info --}}
        <x-monitor::card class="flex flex-col p-4">
            <h3 class="pb-4 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.info') }}</h3>
            <dl class="flex flex-col gap-3">
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.id') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ $userId }}">{{ $userId }}</dd>
                </div>
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.last_seen') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $lastSeen ? Format::datetime($lastSeen).' '.$tz : '—' }}</dd>
                </div>
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.filter_by') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="flex flex-wrap justify-end gap-1">
                        @foreach ($filters as $filter)
                            <a href="{{ route('monitor.dashboard', ['tab' => $filter['tab'], 'userId' => $userId] + $range) }}"
                               class="inline-flex items-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 px-1.5 py-0.5 font-mono text-[11px] text-neutral-600 dark:text-neutral-300 hover:bg-neutral-100 dark:hover:bg-neutral-800">
                                {{ $filter['label'] }}
                            </a>
                        @endforeach
                    </dd>
                </div>
            </dl>
        </x-monitor::card>

        <x-monitor::requests-chart-card
            :count="$stats->count" :ok="$okRequests" :client="$clientErrors" :server="$serverErrors"
            :ok-buckets="$okBuckets" :client-buckets="$clientErrorBuckets" :server-buckets="$serverErrorBuckets"
            :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- start card top/slowest routes --}}
    <div class="mt-1.5 grid grid-cols-1 gap-1.5 lg:grid-cols-2">
        <x-monitor::card class="flex flex-col p-4">
            <h3 class="pb-4 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.top_routes') }}</h3>
            @if ($topRoutes->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_routes_recorded_in_period') }}</p>
            @else
                <dl class="flex flex-col gap-1.5">
                    @foreach ($topRoutes as $route)
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="min-w-0 truncate">
                                <a href="{{ route('monitor.requests.routes.show', ['hash' => KeyHash::for($route->key)] + $range) }}"
                                   class="font-mono text-xs text-neutral-700 dark:text-neutral-200 hover:underline">
                                    <span class="text-neutral-400 dark:text-neutral-500">{{ $route->method }}</span> {{ $route->path }}
                                </a>
                            </dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ number_format($route->count) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-monitor::card>

        <x-monitor::card class="flex flex-col p-4">
            <h3 class="pb-4 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.slowest_routes') }}</h3>
            @if ($slowestRoutes->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_routes_recorded_in_period') }}</p>
            @else
                <dl class="flex flex-col gap-1.5">
                    @foreach ($slowestRoutes as $route)
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="min-w-0 truncate">
                                <a href="{{ route('monitor.requests.routes.show', ['hash' => KeyHash::for($route->key)] + $range) }}"
                                   class="font-mono text-xs text-neutral-700 dark:text-neutral-200 hover:underline">
                                    <span class="text-neutral-400 dark:text-neutral-500">{{ $route->method }}</span> {{ $route->path }}
                                </a>
                            </dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="shrink-0 font-mono text-xs text-amber-600 dark:text-amber-400">{{ $fmt($route->p95_duration) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-monitor::card>
    </div>
    {{-- end card top/slowest routes --}}

    {{-- Individual requests --}}
    <div class="mt-6">
        <div class="flex flex-wrap items-center justify-between gap-2 px-1 pb-3">
            <div class="flex items-center gap-2">
                <x-monitor::icon :path="Icons::REQUESTS" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
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
                            @php
                                $status = (int) ($entry->payload['status'] ?? 0);
                                $detailUrl = ($entry->request_id ?? null) ? route('monitor.requests.routes.request', ['hash' => KeyHash::for($entry->key), 'requestId' => $entry->request_id]) : null;
                            @endphp
                            <tr @if ($detailUrl) onclick="window.location='{{ $detailUrl }}'" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @else class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @endif>
                                <td class="py-2 pr-3 font-mono text-xs">
                                    @if ($detailUrl)
                                        <a href="{{ $detailUrl }}" class="text-blue-600 hover:underline dark:text-blue-400" onclick="event.stopPropagation()">{{ Format::datetime($entry->created_at) }}</a>
                                    @else
                                        <span class="text-neutral-700 dark:text-neutral-200">{{ Format::datetime($entry->created_at) }}</span>
                                    @endif
                                    <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span>
                                </td>
                                <td class="py-2 pr-3 font-mono text-xs uppercase tracking-tight {{ Format::httpMethodClass($entry->payload['method'] ?? null) }}">{{ $entry->payload['method'] ?? '—' }}</td>
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
