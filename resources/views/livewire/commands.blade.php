@php
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\KeyHash;

    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);

    $columns = [
        'key' => ['label' => __('monitor::messages.common.command'), 'align' => 'left'],
        'success' => ['label' => __('monitor::messages.common.success'), 'align' => 'right'],
        'failed' => ['label' => __('monitor::messages.common.failed'), 'align' => 'right'],
        'total' => ['label' => __('monitor::messages.common.total'), 'align' => 'right'],
        'avg_duration' => ['label' => __('monitor::messages.common.avg'), 'align' => 'right'],
        'p95_duration' => ['label' => __('monitor::messages.common.p95'), 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        <x-slot:actions>
            <button type="button" wire:click="$refresh" title="{{ __('monitor::messages.common.refresh') }}"
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
            <x-monitor::card class="flex flex-col p-4">
                <x-monitor::metric :label="__('monitor::messages.common.calls')" :value="number_format($success + $failed)">
                    <x-monitor::legend :label="__('monitor::messages.common.unsuccessful')" dot="bg-rose-500" :value="number_format($failed)" :color="$failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-900 dark:text-neutral-100'"/>
                    <x-monitor::legend :label="__('monitor::messages.common.successful')" dot="bg-emerald-500" :value="number_format($success)"/>
                </x-monitor::metric>
                <div class="mt-5">
                    <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]" :series="[
                        ['label' => __('monitor::messages.common.successful'), 'dot' => 'bg-emerald-500', 'data' => $successBuckets],
                        ['label' => __('monitor::messages.common.unsuccessful'), 'dot' => 'bg-rose-500', 'data' => $failedBuckets],
                    ]"/>
                </div>
                <x-monitor::chart-footer :since="$since" :until="$until"/>
            </x-monitor::card>
            <x-monitor::duration-chart-card :label="__('monitor::messages.common.duration')" :duration="$duration" :since="$since" :until="$until" :threshold="$threshold" height="h-[167px]"/>
        </div>

        {{-- Command table --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalCommands) }} {{ trans_choice('monitor::messages.common.command_count', $totalCommands) }}</h3>
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search_commands') }}"
                       class="h-8 w-56 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
            </div>
        </div>

        @if ($commands->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.commands')" :message="__('monitor::messages.common.no_commands_recorded')" :period-phrase="$periodPhrase"/>
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
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($commands as $command)
                            <tr class="group cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                onclick="window.location='{{ route('monitor.commands.show', ['hash' => KeyHash::for($command->key)] + $range) }}'">
                                <td class="max-w-[18rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $command->key }}">{{ $command->key }}</td>
                                <td class="py-2 text-right font-mono text-xs text-emerald-600 dark:text-emerald-400">{{ number_format($command->success) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $command->failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($command->failed) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ number_format($command->total) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($command->avg_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($command->avg_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($command->p95_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($command->p95_duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <div class="mt-3 flex items-center justify-between border-t border-neutral-100 dark:border-neutral-800 pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>{{ __('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalCommands), 'total' => number_format($totalCommands)]) }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="previousPage" @disabled($page <= 1)
                                    class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">{{ __('monitor::messages.common.prev') }}</button>
                            <span>{{ $page }} / {{ $lastPage }}</span>
                            <button type="button" wire:click="nextPage" @disabled($page >= $lastPage)
                                    class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">{{ __('monitor::messages.common.next') }}</button>
                        </div>
                    </div>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
