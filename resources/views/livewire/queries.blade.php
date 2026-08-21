@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);

    $columns = [
        'key' => ['label' => __('monitor::messages.common.query'), 'align' => 'left'],
        'connection' => ['label' => __('monitor::messages.common.connection'), 'align' => 'left'],
        'calls' => ['label' => __('monitor::messages.common.calls'), 'align' => 'right'],
        'total' => ['label' => __('monitor::messages.common.total'), 'align' => 'right'],
        'avg' => ['label' => __('monitor::messages.common.avg'), 'align' => 'right'],
        'p95' => ['label' => __('monitor::messages.common.p95'), 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <select wire:model.live="connection" class="h-8 rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-neu-inset dark:shadow-neu-dark-inset">
                    <option value="">{{ __('monitor::messages.common.all_connections') }}</option>
                    @foreach ($connections as $conn)
                        <option value="{{ $conn }}">{{ $conn }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="$refresh" title="{{ __('monitor::messages.common.refresh') }}"
                        class="flex h-8 w-8 items-center justify-center rounded-xl bg-neutral-200 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-400 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset">
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
            <x-monitor::card class="flex flex-col p-4">
                <x-monitor::metric :label="__('monitor::messages.common.calls')" :value="number_format($calls)"/>
                <div class="mt-5">
                    <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]"
                        :series="[['label' => __('monitor::messages.common.calls'), 'dot' => 'bg-blue-500', 'data' => $callBuckets]]"/>
                </div>
                <x-monitor::chart-footer :since="$since" :until="$until"/>
            </x-monitor::card>
            <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
        </div>

        {{-- Query table --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalQueries) }} {{ trans_choice('monitor::messages.common.query_count', $totalQueries) }}</h3>
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search_queries') }}"
                       class="h-8 w-56 rounded-xl bg-neutral-200 dark:bg-neutral-800 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-neu-inset dark:shadow-neu-dark-inset">
            </div>
        </div>

        @if ($queries->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.queries')" :message="__('monitor::messages.common.no_queries_recorded')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
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
                        <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                            @foreach ($queries as $query)
                                <tr class="group cursor-pointer rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset"
                                    onclick="window.location='{{ route('monitor.queries.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($query->key)] + $range) }}'">
                                    <td class="max-w-[32rem] py-2 pr-3">
                                        <code data-line-code data-lang="sql" class="block truncate font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $query->key }}">{{ $query->key }}</code>
                                    </td>
                                    <td class="py-2 pr-3">
                                        <span class="inline-flex items-center gap-1.5 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                                            {{ $query->connection }}
                                            @if ($query->connection_type)
                                                <span class="rounded border px-1 font-mono text-[9px] font-medium uppercase leading-tight {{ Format::CONNECTION_TYPE_BADGES[$query->connection_type] }}">{{ $query->connection_type }}</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($query->calls) }}</td>
                                    <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($query->total) }}</td>
                                    <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($query->avg) }}</td>
                                    <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($query->p95) }}</td>
                                    <td class="py-2 pl-2 text-right">
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg text-neutral-300 dark:text-neutral-600 group-hover:bg-neutral-200 dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-neu-sm dark:group-hover:shadow-neu-dark-sm">
                                            <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                            <x-monitor::table-skeleton :columns="7" :rows="count($queries)"/>
                        </tbody>
                    </table>
                </div>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalQueries), 'total' => number_format($totalQueries)])"/>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
