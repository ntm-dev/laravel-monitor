@php
    use LaravelMonitor\Support\Icons;

    $columns = [
        'key' => ['label' => 'Key', 'align' => 'left'],
        'hit_ratio' => ['label' => 'Hit %', 'align' => 'right'],
        'deletes' => ['label' => 'Deletes', 'align' => 'right'],
        'hits' => ['label' => 'Hits', 'align' => 'right'],
        'misses' => ['label' => 'Misses', 'align' => 'right'],
        'writes' => ['label' => 'Writes', 'align' => 'right'],
        'failures' => ['label' => 'Failures', 'align' => 'right'],
        'total' => ['label' => 'Total', 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="Icons::CACHE" title="Cache">
        <x-slot:actions>
            <button type="button" wire:click="$refresh" title="Refresh"
                    class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
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
            <x-monitor::cache-chart-card label="Events" :total="$events" :series="$eventSeries" :since="$since" :until="$until" height="h-[167px]"/>
            <x-monitor::cache-chart-card label="Failures" :total="$failures" :series="$failureSeries" :since="$since" :until="$until" height="h-[167px]"/>
        </div>

        {{-- Key table --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalKeys) }} {{ $totalKeys === 1 ? 'Key' : 'Keys' }}</h3>
        </div>

        @if ($keys->isEmpty())
            <x-monitor::empty-state label="Cache" message="No cache activity recorded" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            @foreach ($columns as $field => $column)
                                <th class="cursor-pointer select-none pb-2 font-normal {{ $column['align'] === 'right' ? 'text-right' : 'text-left' }}"
                                    wire:click="sort('{{ $field }}')">
                                    <span class="inline-flex items-center gap-1 {{ $column['align'] === 'right' ? 'flex-row-reverse' : '' }}">
                                        {{ $column['label'] }}
                                        @if ($sortBy === $field)
                                            <x-monitor::icon :path="Icons::CHEVRON_DOWN" :stroke="2" class="h-3 w-3 {{ $sortDirection === 'asc' ? 'rotate-180' : '' }}"/>
                                        @endif
                                    </span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($keys as $row)
                            <tr class="{{ $row->failures > 0 ? 'bg-rose-50/60 dark:bg-rose-500/10 hover:bg-rose-50 dark:hover:bg-rose-500/10' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50' }}">
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
                    <div class="mt-3 flex items-center justify-between border-t border-neutral-100 dark:border-neutral-800 pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>Showing {{ $from + 1 }}–{{ min($from + $perPage, $totalKeys) }} of {{ number_format($totalKeys) }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="previousPage" @disabled($page <= 1)
                                    class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">Prev</button>
                            <span>{{ $page }} / {{ $lastPage }}</span>
                            <button type="button" wire:click="nextPage" @disabled($page >= $lastPage)
                                    class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">Next</button>
                        </div>
                    </div>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
