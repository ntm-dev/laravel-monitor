<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <select wire:model.live="userId" class="h-8 rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-neu-inset dark:shadow-neu-dark-inset">
                    <option value="">{{ __('monitor::messages.common.all_users') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="$refresh" title="{{ __('monitor::messages.common.refresh') }}"
                        class="flex h-8 w-8 items-center justify-center rounded-xl bg-neutral-200 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-400 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset">
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
                <div class="flex h-8 items-center gap-0.5 rounded-xl bg-neutral-200 dark:bg-neutral-800 p-0.5 shadow-neu-inset dark:shadow-neu-dark-inset">
                    @foreach ($filters as $value => $label)
                        <button type="button" wire:click="setStatus('{{ $value }}')"
                                @class([
                                    'flex h-full items-center rounded-lg px-2.5 text-xs font-medium',
                                    'bg-neutral-900 text-white shadow-neu-sm dark:bg-neutral-500 dark:shadow-neu-dark-sm' => $status === $value,
                                    'text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100' => $status !== $value,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="relative">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search_exceptions') }}"
                           class="h-8 w-56 rounded-xl bg-neutral-200 dark:bg-neutral-800 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-neu-inset dark:shadow-neu-dark-inset">
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
                            <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
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
                        <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                            @foreach ($groups as $group)
                                <tr class="group cursor-pointer rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset"
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
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg text-neutral-300 dark:text-neutral-600 group-hover:bg-neutral-200 dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-neu-sm dark:group-hover:shadow-neu-dark-sm">
                                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                            <x-monitor::table-skeleton :columns="6" :rows="count($groups)"/>
                        </tbody>
                    </table>
                </div>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalGroups), 'total' => number_format($totalGroups)])"/>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
