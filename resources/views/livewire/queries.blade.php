@php
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\Sql;

    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $typeBadge = fn (string $sql) => Sql::isWrite($sql)
        ? 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400'
        : 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400';

    $columns = [
        'key' => ['label' => 'Query', 'align' => 'left'],
        'connection' => ['label' => 'Connection', 'align' => 'left'],
        'calls' => ['label' => 'Calls', 'align' => 'right'],
        'total' => ['label' => 'Total', 'align' => 'right'],
        'avg' => ['label' => 'Avg', 'align' => 'right'],
        'p95' => ['label' => 'P95', 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="Icons::QUERIES" title="Queries">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <select wire:model.live="connection" class="h-8 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
                    <option value="">All connections</option>
                    @foreach ($connections as $conn)
                        <option value="{{ $conn }}">{{ $conn }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="$refresh" title="Refresh"
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
            <x-monitor::card class="flex flex-col p-4">
                <x-monitor::metric label="Calls" :value="number_format($calls)"/>
                <div class="mt-5">
                    <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]"
                        :series="[['label' => 'Calls', 'dot' => 'bg-blue-500', 'data' => $callBuckets]]"/>
                </div>
                <x-monitor::chart-footer :since="$since" :until="$until"/>
            </x-monitor::card>
            <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
        </div>

        {{-- Query table --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalQueries) }} {{ $totalQueries === 1 ? 'Query' : 'Queries' }}</h3>
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search queries…"
                       class="h-8 w-56 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
            </div>
        </div>

        @if ($queries->isEmpty())
            <x-monitor::empty-state label="Queries" message="No queries recorded" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
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
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($queries as $query)
                                <tr class="group cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                    onclick="window.location='{{ route('monitor.dashboard', ['tab' => 'queries', 'key' => $query->key] + $range) }}'">
                                    <td class="max-w-[32rem] py-2 pr-3">
                                        <code data-line-code data-lang="sql" class="block truncate font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $query->key }}">{{ $query->key }}</code>
                                    </td>
                                    <td class="py-2 pr-3">
                                        <span class="inline-flex items-center gap-1.5 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                                            {{ $query->connection }}
                                            <span class="rounded border px-1 font-mono text-[9px] font-medium uppercase leading-tight {{ $typeBadge($query->key) }}">{{ Sql::isWrite($query->key) ? 'Write' : 'Read' }}</span>
                                        </span>
                                    </td>
                                    <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($query->calls) }}</td>
                                    <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($query->total) }}</td>
                                    <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($query->avg) }}</td>
                                    <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($query->p95) }}</td>
                                    <td class="py-2 pl-2 text-right">
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-sm">
                                            <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($lastPage > 1)
                    <div class="mt-3 flex items-center justify-between border-t border-neutral-100 dark:border-neutral-800 pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>Showing {{ $from + 1 }}–{{ min($from + $perPage, $totalQueries) }} of {{ number_format($totalQueries) }}</span>
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
