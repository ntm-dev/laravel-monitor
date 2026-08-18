<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::EXCEPTIONS" :title="__('monitor::messages.nav.exceptions')">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <select wire:model.live="userId" class="h-8 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
                    <option value="">{{ __('monitor::messages.common.all_users') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="$refresh" title="{{ __('monitor::messages.common.refresh') }}"
                        class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::REFRESH" :stroke="1.8" class="h-3.5 w-3.5"/>
                </button>
            </div>
        </x-slot:actions>

        {{-- Overview: total occurrences + handled / unhandled timeline --}}
        <div x-data="{
                 hoverIndex: null,
                 setHoverIndex(i) { this.hoverIndex = i },
                 clearHoverIndex() { this.hoverIndex = null },
             }">
            <x-monitor::exceptions-chart-card
                :count="$total" :handled="$handledCount" :unhandled="$unhandledCount"
                :handled-buckets="$handledBuckets" :unhandled-buckets="$unhandledBuckets"
                :since="$since" :until="$until" height="h-[167px]"/>
        </div>

        {{-- Grouped exception table --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalGroups) }} {{ trans_choice('monitor::messages.common.exception_count', $totalGroups) }}</h3>
            <div class="flex items-center gap-2">
                <div class="flex h-8 items-center gap-0.5 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-0.5 shadow-sm">
                    @foreach ($filters as $value => $label)
                        <button type="button" wire:click="setStatus('{{ $value }}')"
                                @class([
                                    'flex h-full items-center rounded-md px-2.5 text-xs font-medium',
                                    'bg-neutral-900 text-white dark:bg-neutral-500' => $status === $value,
                                    'text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100' => $status !== $value,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="relative">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search_exceptions') }}"
                           class="h-8 w-56 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
                </div>
            </div>
        </div>

        @if ($groups->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.exceptions')" :message="__('monitor::messages.common.no_exceptions_reported')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                @foreach ($columns as $field => $column)
                                    <th @class([
                                            'pb-2 font-normal',
                                            'text-right' => $column['align'] === 'right',
                                            'cursor-pointer select-none' => $column['sortable'] ?? false,
                                        ])
                                        @if ($column['sortable'] ?? false) wire:click="sort('{{ $field }}')" @endif>
                                        @if ($column['sortable'] ?? false)
                                            <span class="inline-flex items-center gap-1 {{ $column['align'] === 'right' ? 'flex-row' : '' }}">
                                                {{ $column['label'] }}
                                                <div class="-right-3 flex flex-col gap-[2px]">
                                                    <div class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px]
                                                        border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden
                                                        {{ $sortBy === $field && $sortDirection === 'asc' ? 'border-b-blue-500' : '' }}"
                                                    >
                                                    </div>
                                                    <div class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px]
                                                        border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden
                                                        {{ $sortBy === $field && $sortDirection !== 'asc' ? 'border-b-blue-500' : '' }} rotate-180"
                                                    >
                                                    </div>
                                                </div>
                                            </span>
                                        @endif
                                    </th>
                                @endforeach
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($groups as $group)
                                <tr class="group cursor-pointer {{ $group->unhandled > 0 ? 'hover:bg-rose-50/50 dark:hover:bg-rose-500/10' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50' }}"
                                    onclick="window.location='{{ route('monitor.exceptions.show', ['hash' => $group->key] + $range) }}'">
                                    <td class="whitespace-nowrap py-2.5 pr-3 font-mono text-xs text-neutral-500 dark:text-neutral-400" title="{{ $group->last_seen_full }}">
                                        {{ $group->last_seen_human }}
                                    </td>
                                    <td class="py-2.5 pr-3">
                                        <x-monitor::status-badge :handled="$group->handled"/>
                                    </td>
                                    <td class="max-w-[26rem] py-2.5 pr-3">
                                        <p class="truncate font-mono text-xs font-medium {{ $group->unhandled > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-800 dark:text-neutral-200' }}" title="{{ $group->class }}">{{ $group->class }}</p>
                                        @if (filled($group->message))
                                            <p class="mt-0.5 truncate text-xs text-neutral-400 dark:text-neutral-500" title="{{ $group->message }}">{{ $group->message }}</p>
                                        @elseif (filled($group->file))
                                            <p class="mt-0.5 truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $group->file }}:{{ $group->line }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ number_format($group->count) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $group->users > 0 ? number_format($group->users) : '—' }}</td>
                                    <td class="py-2.5 pl-2 text-right">
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-sm">
                                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($lastPage > 1)
                    <div class="mt-3 flex items-center justify-between border-t border-neutral-100 dark:border-neutral-800 pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>{{ __('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalGroups), 'total' => number_format($totalGroups)]) }}</span>
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
