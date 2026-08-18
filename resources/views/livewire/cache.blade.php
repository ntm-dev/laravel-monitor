@php
    use LaravelMonitor\Support\Icons;

    $columns = [
        'key' => ['label' => __('monitor::messages.common.key'), 'align' => 'left'],
        'hit_ratio' => ['label' => __('monitor::messages.common.hit_ratio'), 'align' => 'right'],
        'deletes' => ['label' => __('monitor::messages.common.deletes'), 'align' => 'right'],
        'hits' => ['label' => __('monitor::messages.common.hits'), 'align' => 'right'],
        'misses' => ['label' => __('monitor::messages.common.misses'), 'align' => 'right'],
        'writes' => ['label' => __('monitor::messages.common.writes'), 'align' => 'right'],
        'failures' => ['label' => __('monitor::messages.common.failures'), 'align' => 'right'],
        'total' => ['label' => __('monitor::messages.common.total'), 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        <x-slot:actions>
            <button type="button" wire:click="$refresh" title="{{ __('monitor::messages.common.refresh') }}"
                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-neutral-200 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-400 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset">
                <x-monitor::icon :path="Icons::REFRESH" :stroke="1.8" class="h-3.5 w-3.5"/>
            </button>
        </x-slot:actions>

        {{-- Overview charts --}}
        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
             x-data="{
                 hoverIndex: null,
                 setHoverIndex(i) { this.hoverIndex = i },
                 clearHoverIndex() { this.hoverIndex = null },
             }">
            <x-monitor::cache-chart-card :label="__('monitor::messages.common.events')" :total="$events" :series="$eventSeries" :since="$since" :until="$until" height="h-[167px]"/>
            <x-monitor::cache-chart-card :label="__('monitor::messages.common.failures')" :total="$failures" :series="$failureSeries" :since="$since" :until="$until" height="h-[167px]"/>
        </div>

        {{-- Key table --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalKeys) }} {{ trans_choice('monitor::messages.common.key_count', $totalKeys) }}</h3>
        </div>

        @if ($keys->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.cache')" :message="__('monitor::messages.common.no_cache_activity_recorded')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            @foreach ($columns as $field => $column)
                                <th class="cursor-pointer select-none pb-2 font-normal {{ $column['align'] === 'right' ? 'text-right' : 'text-left' }}"
                                    wire:click="sort('{{ $field }}')">
                                    <span class="inline-flex items-center gap-1 {{ $column['align'] === 'right' ? 'flex-row' : '' }}">
                                        {{ $column['label'] }}
                                        <div class="-right-3 flex flex-col gap-[2px]">
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
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                        @foreach ($keys as $row)
                            <tr class="{{ $row->failures > 0 ? 'bg-rose-50/60 dark:bg-rose-500/10 rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset' : 'rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset' }}">
                                <td class="max-w-[24rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $row->key }}">{{ $row->key }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $row->hit_ratio !== null && $row->hit_ratio < 50 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $row->hit_ratio !== null ? $row->hit_ratio.'%' : '—' }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($row->deletes) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($row->hits) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($row->misses) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($row->writes) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $row->failures > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ number_format($row->failures) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($row->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <div class="mt-3 flex items-center justify-between shadow-[0_-1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_-1px_0_rgba(255,255,255,0.06)] pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>{{ __('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalKeys), 'total' => number_format($totalKeys)]) }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="previousPage" @disabled($page <= 1)
                                    class="rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2.5 py-1 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset disabled:opacity-40 disabled:shadow-none disabled:hover:shadow-none">{{ __('monitor::messages.common.prev') }}</button>
                            <span>{{ $page }} / {{ $lastPage }}</span>
                            <button type="button" wire:click="nextPage" @disabled($page >= $lastPage)
                                    class="rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2.5 py-1 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset disabled:opacity-40 disabled:shadow-none disabled:hover:shadow-none">{{ __('monitor::messages.common.next') }}</button>
                        </div>
                    </div>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
