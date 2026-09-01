@php
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\KeyHash;

    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);

    $columns = [
        'key' => ['label' => __('monitor::messages.common.domain'), 'align' => 'left'],
        'success' => ['label' => __('monitor::messages.common.ok_status'), 'align' => 'right'],
        'client_errors' => ['label' => __('monitor::messages.common.client_error'), 'align' => 'right'],
        'server_errors' => ['label' => __('monitor::messages.common.server_error'), 'align' => 'right'],
        'network_errors' => ['label' => __('monitor::messages.common.failed'), 'align' => 'right'],
        'count' => ['label' => __('monitor::messages.common.total'), 'align' => 'right'],
        'avg_duration' => ['label' => __('monitor::messages.common.avg'), 'align' => 'right'],
        'p95_duration' => ['label' => __('monitor::messages.common.p95'), 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        {{-- Overview charts --}}
        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
             x-data="{
                 hoverIndex: null,
                 setHoverIndex(i) { this.hoverIndex = i },
                 clearHoverIndex() { this.hoverIndex = null },
             }">
            <x-monitor::requests-chart-card
                :count="$requests->count" :ok="$okRequests" :client="$clientErrors" :server="$serverErrors" :failed="$networkErrors"
                :ok-buckets="$okBuckets" :client-buckets="$clientErrorBuckets" :server-buckets="$serverErrorBuckets" :failed-buckets="$networkErrorBuckets"
                :since="$since" :until="$until" height="h-[167px]"/>
            <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" :threshold="$threshold" height="h-[167px]"/>
        </div>

        {{-- Domain table --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalDomains) }} {{ trans_choice('monitor::messages.common.domain_count', $totalDomains) }}</h3>
        </div>

        @if ($domains->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.outgoing')" :message="__('monitor::messages.common.no_outgoing_requests')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
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
                    <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($domains as $domain)
                            <tr class="group cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                onclick="window.location='{{ route('monitor.outgoing.show', ['hash' => KeyHash::for($domain->key)] + $range) }}'">
                                <td class="max-w-[16rem] py-2 pr-2" data-tooltip="{{ $domain->key }}">
                                    <span class="flex items-center gap-1.5">
                                        <x-monitor::icon :path="Icons::REQUESTS" :stroke="1.8" class="h-3.5 w-3.5 shrink-0 text-neutral-400 dark:text-neutral-500 group-hover:text-blue-600 dark:group-hover:text-blue-400"/>
                                        <span class="truncate font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $domain->key }}</span>
                                    </span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($domain->success) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $domain->client_errors > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ number_format($domain->client_errors) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $domain->server_errors > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ number_format($domain->server_errors) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $domain->network_errors > 0 ? 'text-violet-600 dark:text-violet-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ number_format($domain->network_errors) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($domain->count) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($domain->avg_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($domain->avg_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($domain->p95_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($domain->p95_duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-emerald-600 dark:group-hover:text-emerald-300 group-hover:shadow-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-100 dark:divide-neutral-800">
                        <x-monitor::table-skeleton :columns="9" :rows="count($domains)"/>
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalDomains), 'total' => number_format($totalDomains)])"/>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
