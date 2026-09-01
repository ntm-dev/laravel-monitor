@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\KeyHash;

    $fmt = fn ($ms) => Format::duration($ms);

    $columns = [
        'key' => ['label' => __('monitor::messages.common.route'), 'align' => 'left'],
        'success' => ['label' => __('monitor::messages.common.ok_status'), 'align' => 'right'],
        'client_errors' => ['label' => __('monitor::messages.common.client_error'), 'align' => 'right'],
        'server_errors' => ['label' => __('monitor::messages.common.server_error'), 'align' => 'right'],
        'count' => ['label' => __('monitor::messages.common.total'), 'align' => 'right'],
        'avg_duration' => ['label' => __('monitor::messages.common.avg'), 'align' => 'right'],
        'p95_duration' => ['label' => __('monitor::messages.common.p95'), 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <select wire:model.live="userId" class="h-8 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
                    <option value="">{{ __('monitor::messages.common.all_users') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="$refresh" data-tooltip="{{ __('monitor::messages.common.refresh') }}"
                        class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                    <x-monitor::icon :path="Icons::REFRESH" :stroke="1.8" class="h-3.5 w-3.5"/>
                </button>
            </div>
        </x-slot:actions>

        {{-- Overview charts --}}
        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
             x-data="{
                 hoverIndex: null,
                 setHoverIndex(i) { this.hoverIndex = i },
                 clearHoverIndex() { this.hoverIndex = null },
             }">
            <x-monitor::requests-chart-card
                :count="$requests->count" :ok="$okRequests" :client="$clientErrors" :server="$serverErrors"
                :ok-buckets="$okBuckets" :client-buckets="$clientErrorBuckets" :server-buckets="$serverErrorBuckets"
                :since="$since" :until="$until" height="h-[167px]"/>
            <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" :threshold="$threshold" height="h-[167px]"/>
        </div>

        {{-- Route table --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalRoutes) }} {{ trans_choice('monitor::messages.common.routes_count', $totalRoutes) }}</h3>
            <div class="flex items-center gap-2">
                <div class="relative">
                    <x-monitor::icon :path="Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search_routes') }}"
                           class="h-8 w-56 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 {{ $search !== '' ? 'pr-7' : 'pr-2' }} text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
                    @if ($search !== '')
                        <button type="button" wire:click="clearSearch" data-tooltip="{{ __('monitor::messages.common.clear') }}"
                                class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-blue-600 dark:text-neutral-500 dark:hover:text-blue-400">
                            <x-monitor::icon :path="Icons::CLOSE" :stroke="2" class="h-3.5 w-3.5"/>
                        </button>
                    @endif
                </div>
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
            </div>
        </div>

        @if ($routes->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.requests')" :message="__('monitor::messages.common.no_requests_recorded')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.method') }}</th>
                            @foreach ($columns as $field => $column)
                                <th class="cursor-pointer select-none pb-2 font-normal {{ $column['align'] === 'right' ? 'text-right' : 'text-left' }}"
                                    wire:click="sort('{{ $field }}')">
                                    <span class="inline-flex items-center gap-1 {{ $column['align'] === 'right' ? 'flex-row' : '' }}">
                                        {{ $column['label'] }}
                                        <div class=" -right-3 flex flex-col gap-[2px]">
                                            <div
                                                class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px] border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden {{ $sortBy === $field && $sortDirection === 'asc' ? 'border-b-blue-500' : '' }}"
                                            >
                                            </div>
                                            <div
                                                class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px] border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden {{ $sortBy === $field && $sortDirection !== 'asc' ? 'border-b-blue-500' : '' }} rotate-180"
                                            >
                                            </div>
                                        </div>
                                    </span>
                                </th>
                            @endforeach
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage,setDurationFilter,search" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($routes as $route)
                            <tr class="group cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                onclick="window.location='{{ route('monitor.requests.routes.show', ['hash' => KeyHash::for($route->key)] + $range) }}'">
                                <td class="py-2 pr-2 font-mono text-xs uppercase tracking-tight {{ Format::httpMethodClass($route->method) }}">{{ $route->method }}</td>
                                <td class="max-w-[14rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" data-tooltip="{{ $route->key }}">{{ $route->path }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($route->success) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $route->client_errors > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        @if ($route->client_errors > 0)
                                            <svg width="10" height="10" viewBox="0 0 10 10" class="size-3! fill-amber-500" xmlns="http://www.w3.org/2000/svg"><path d="M9.87503 7.8287L5.9269 0.549947C5.82953 0.369362 5.68104 0.221523 5.50003 0.124947C5.25411 -0.00665839 4.96617 -0.0358401 4.69884 0.0437494C4.43151 0.123339 4.20642 0.305262 4.07253 0.549947L0.125031 7.8287C0.0387135 7.98887 -0.00444477 8.16875 -0.000203238 8.35066C0.00403829 8.53256 0.0555336 8.71024 0.149223 8.86622C0.242912 9.0222 0.375571 9.15112 0.534164 9.24031C0.692758 9.32951 0.871828 9.3759 1.05378 9.37495H8.94628C9.12068 9.37495 9.2924 9.33202 9.44628 9.24995C9.56819 9.18524 9.67609 9.09703 9.76375 8.99041C9.8514 8.8838 9.91708 8.76088 9.957 8.62876C9.99692 8.49663 10.0103 8.35791 9.99632 8.22059C9.98236 8.08328 9.94072 7.95009 9.87503 7.8287ZM5.00003 8.12495C4.87642 8.12495 4.75558 8.08829 4.6528 8.01962C4.55002 7.95094 4.46991 7.85333 4.42261 7.73912C4.3753 7.62492 4.36292 7.49925 4.38704 7.37802C4.41116 7.25678 4.47068 7.14541 4.55809 7.05801C4.6455 6.9706 4.75686 6.91107 4.8781 6.88696C4.99934 6.86284 5.125 6.87522 5.23921 6.92252C5.35341 6.96983 5.45102 7.04993 5.5197 7.15272C5.58837 7.2555 5.62503 7.37633 5.62503 7.49995C5.62503 7.66571 5.55918 7.82468 5.44197 7.94189C5.32476 8.0591 5.16579 8.12495 5.00003 8.12495ZM5.62503 5.93745C5.62503 6.02033 5.59211 6.09981 5.5335 6.15842C5.4749 6.21702 5.39541 6.24995 5.31253 6.24995H4.68753C4.60465 6.24995 4.52516 6.21702 4.46656 6.15842C4.40795 6.09981 4.37503 6.02033 4.37503 5.93745V3.43745C4.37503 3.35457 4.40795 3.27508 4.46656 3.21648C4.52516 3.15787 4.60465 3.12495 4.68753 3.12495H5.31253C5.39541 3.12495 5.4749 3.15787 5.5335 3.21648C5.59211 3.27508 5.62503 3.35457 5.62503 3.43745V5.93745Z"></path></svg>
                                        @endif
                                        {{ number_format($route->client_errors) }}
                                    </span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs {{ $route->server_errors > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-600 dark:text-neutral-300' }}">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        @if ($route->server_errors > 0)
                                            <svg viewBox="0 0 10 10" fill="currentColor" class="size-3! fill-rose-500" xmlns="http://www.w3.org/2000/svg"><path d="M9.81687 2.68313L7.31687 0.183125C7.19969 0.0659067 7.04075 3.53984e-05 6.875 0L3.125 0C2.95925 3.53984e-05 2.80031 0.0659067 2.68313 0.183125L0.183125 2.68313C0.0659067 2.80031 3.53984e-05 2.95925 0 3.125L0 6.875C3.53984e-05 7.04075 0.0659067 7.19969 0.183125 7.31687L2.68313 9.81687C2.80031 9.93409 2.95925 9.99996 3.125 10H6.875C7.04075 9.99996 7.19969 9.93409 7.31687 9.81687L9.81687 7.31687C9.93409 7.19969 9.99996 7.04075 10 6.875V3.125C9.99996 2.95925 9.93409 2.80031 9.81687 2.68313ZM5 7.5C4.87639 7.5 4.75555 7.46334 4.65277 7.39467C4.54999 7.32599 4.46988 7.22838 4.42257 7.11418C4.37527 6.99997 4.36289 6.87431 4.38701 6.75307C4.41112 6.63183 4.47065 6.52047 4.55806 6.43306C4.64547 6.34565 4.75683 6.28612 4.87807 6.26201C4.99931 6.23789 5.12497 6.25027 5.23918 6.29757C5.35338 6.34488 5.45099 6.42499 5.51967 6.52777C5.58834 6.63055 5.625 6.75139 5.625 6.875C5.625 7.04076 5.55915 7.19973 5.44194 7.31694C5.32473 7.43415 5.16576 7.5 5 7.5ZM5.625 5.3125C5.625 5.39538 5.59208 5.47487 5.53347 5.53347C5.47487 5.59208 5.39538 5.625 5.3125 5.625H4.6875C4.60462 5.625 4.52513 5.59208 4.46653 5.53347C4.40792 5.47487 4.375 5.39538 4.375 5.3125V2.8125C4.375 2.72962 4.40792 2.65013 4.46653 2.59153C4.52513 2.53292 4.60462 2.5 4.6875 2.5H5.3125C5.39538 2.5 5.47487 2.53292 5.53347 2.59153C5.59208 2.65013 5.625 2.72962 5.625 2.8125V5.3125Z"></path></svg>
                                        @endif
                                        {{ number_format($route->server_errors) }}
                                    </span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($route->count) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($route->avg_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($route->avg_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($route->p95_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($route->p95_duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-emerald-600 dark:group-hover:text-emerald-300 group-hover:shadow-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage,setDurationFilter,search" class="hidden animate-pulse divide-y divide-neutral-100 dark:divide-neutral-800">
                        <x-monitor::table-skeleton :columns="9" :rows="count($routes)"/>
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalRoutes), 'total' => number_format($totalRoutes)])"/>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
