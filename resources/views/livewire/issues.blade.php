@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $glitch = collect(range(1, 60))->map(fn ($i) => strtoupper(base_convert(md5('monitor'.$i), 16, 36)))->implode(' ');
    $actionButton = 'shrink-0 whitespace-nowrap rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50 hover:text-neutral-900 dark:hover:text-neutral-100';
    $pagePairs = $view === 'exceptions'
        ? $exceptions->map(fn ($e) => ['exception', $e->key])->values()
        : $performance->map(fn ($item) => [$item->issue_type, $item->key])->values();

    // Computed here (not passed from Issues::data()) since Card already
    // declares a $from property of its own (the custom-range picker's start
    // date) — a same-named 'from' key returned from data() would just get
    // silently overridden by that unrelated property in the view.
    $from = ($page - 1) * $perPage;
@endphp
{{-- Row selection lives entirely in Alpine (`selected`, keyed the same way
     the old server-side $selected array was: selected[type][key] = true) —
     checking a box is pure client state and shouldn't cost a Livewire
     round-trip; only resolveSelected()/ignoreSelected() below ever send it
     to the server, as the pairs list a bulk action applies to. --}}
<div wire:poll.{{ $refresh }}s
     x-data="{
        selected: {},
        toggle(type, key) {
            const bucket = this.selected[type] ??= {};
            if (bucket[key]) { delete bucket[key]; if (Object.keys(bucket).length === 0) delete this.selected[type]; }
            else { bucket[key] = true; }
        },
        isSelected(type, key) { return !!this.selected[type]?.[key]; },
        selectAll(pairs) { pairs.forEach(([type, key]) => { (this.selected[type] ??= {})[key] = true; }); },
        allSelectedOnPage(pairs) { return pairs.length > 0 && pairs.every(([type, key]) => this.isSelected(type, key)); },
        clear() { this.selected = {}; },
        pairs() { return Object.entries(this.selected).flatMap(([type, keys]) => Object.keys(keys).map((key) => [type, key])); },
        count() { return this.pairs().length; },
        selectedText: {{ Js::from(__('monitor::messages.issue.selected')) }},
     }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex h-9 items-center gap-0.5 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-0.5 shadow-sm">
            @foreach (['exceptions' => [__('monitor::messages.nav.exceptions'), $exceptionCount], 'performance' => [__('monitor::messages.issue.performance'), $performanceCount]] as $issueTab => [$issueLabel, $issueCount])
                <button type="button" wire:click="$set('view', '{{ $issueTab }}')" @click="clear()"
                        @class([
                            'flex h-full items-center gap-2 rounded-md border px-3 text-sm',
                            'border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 text-neutral-900 dark:text-neutral-100' => $view === $issueTab,
                            'border-transparent text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100' => $view !== $issueTab,
                        ])>
                    {{ $issueLabel }}
                    <span class="rounded bg-neutral-200/80 dark:bg-neutral-700/80 px-1.5 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ $issueCount }}</span>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search') }}"
                       class="h-9 w-52 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-3 text-sm text-neutral-700 dark:text-neutral-200 shadow-sm placeholder:text-neutral-400 dark:placeholder:text-neutral-500 focus:outline-none">
            </div>
            <div class="flex h-9 items-center gap-0.5 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-0.5 text-sm shadow-sm">
                @foreach (['open' => __('monitor::messages.issue.status_open'), 'resolved' => __('monitor::messages.issue.status_resolved'), 'ignored' => __('monitor::messages.issue.status_ignored')] as $statusKey => $statusLabel)
                    <button type="button" wire:click="$set('status', '{{ $statusKey }}')" @click="clear()"
                            @class([
                                'flex h-full items-center gap-2 rounded-md border px-3',
                                'border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 text-neutral-900 dark:text-neutral-100' => $status === $statusKey,
                                'border-transparent text-neutral-400 dark:text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100' => $status !== $statusKey,
                            ])>
                        {{ $statusLabel }}
                        @if ($statusKey === 'open' && $openIssueCount > 0)
                            <span class="rounded bg-neutral-200/80 dark:bg-neutral-700/80 px-1.5 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ $openIssueCount }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <div x-show="count() > 0" x-cloak class="mt-3 flex items-center gap-3 rounded-lg border border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 px-3 py-2 text-sm">
        <span class="font-medium text-blue-700 dark:text-blue-300" x-text="selectedText.replace(':count', count())"></span>
        <button type="button" wire:click="$wire.resolveSelected(pairs()).then(() => clear())" class="{{ $actionButton }}">{{ __('monitor::messages.issue.resolve') }}</button>
        <button type="button" wire:click="$wire.ignoreSelected(pairs()).then(() => clear())" class="{{ $actionButton }}">{{ __('monitor::messages.issue.ignore') }}</button>
        <button type="button" @click="clear()" class="ml-auto text-xs text-blue-700 dark:text-blue-300 hover:underline">{{ __('monitor::messages.issue.clear') }}</button>
    </div>

    <div class="mt-4">
        @if ($view === 'exceptions' && $exceptions->isNotEmpty())
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="w-8 pb-2">
                                    <input type="checkbox" x-data="{ pagePairs: {{ Js::from($pagePairs) }} }"
                                           :checked="allSelectedOnPage(pagePairs)"
                                           @click="allSelectedOnPage(pagePairs) ? clear() : selectAll(pagePairs)">
                                </th>
                                <th class="w-12 cursor-pointer select-none pb-2 font-normal" wire:click="sort('id')">
                                    <span class="inline-flex items-center gap-1">
                                        #
                                        <x-monitor::sort-caret field="id" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="w-8 cursor-pointer select-none pb-2 pr-4 font-normal" wire:click="sort('priority')">
                                    <span class="inline-flex items-center gap-1" title="{{ __('monitor::messages.issue.sort_by_priority') }}">
                                        <x-monitor::priority-bars priority="none" class="{{ $sortBy === 'priority' ? 'opacity-100' : 'opacity-40' }}"/>
                                        <x-monitor::sort-caret field="priority" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 font-normal" wire:click="sort('label')">
                                    <span class="inline-flex items-center gap-1">
                                        {{ __('monitor::messages.common.issue') }}
                                        <x-monitor::sort-caret field="label" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 text-right font-normal" wire:click="sort('count')">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        {{ __('monitor::messages.common.count') }}
                                        <x-monitor::sort-caret field="count" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 text-right font-normal" wire:click="sort('users')">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        {{ __('monitor::messages.nav.users') }}
                                        <x-monitor::sort-caret field="users" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 text-right font-normal" wire:click="sort('first_seen')">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        {{ __('monitor::messages.common.first_seen') }}
                                        <x-monitor::sort-caret field="first_seen" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 text-right font-normal" wire:click="sort('last_seen')">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        {{ __('monitor::messages.common.last_seen') }}
                                        <x-monitor::sort-caret field="last_seen" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($exceptions as $exception)
                                <tr wire:key="issue-exception-{{ $exception->key }}" class="group hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="py-2.5 pr-2">
                                        <input type="checkbox" :checked="isSelected('exception', {{ Js::from($exception->key) }})"
                                               @click="toggle('exception', {{ Js::from($exception->key) }})">
                                    </td>
                                    <td class="py-2.5 pr-2 font-mono text-xs text-neutral-400 dark:text-neutral-500">#{{ $exception->id }}</td>
                                    <td class="py-2.5 pr-4">
                                        <x-monitor::issues.priority-picker type="exception" :issue-key="$exception->key" :priority="$exception->priority"/>
                                    </td>
                                    <td class="max-w-[26rem] cursor-pointer py-2.5 pr-3" onclick="window.location='{{ route('monitor.issues.show', $exception->uuid) }}'">
                                        <p class="truncate font-mono text-xs font-medium text-neutral-800 dark:text-neutral-200" title="{{ $exception->latest['class'] ?? $exception->key }}">{{ $exception->latest['class'] ?? $exception->key }}</p>
                                        @if (($exception->latest['message'] ?? '') !== '')
                                            <p class="mt-0.5 line-clamp-1 text-xs text-neutral-400 dark:text-neutral-500">{{ $exception->latest['message'] }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ number_format($exception->count) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $exception->users > 0 ? number_format($exception->users) : '—' }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $exception->first_seen?->diffForHumans() }}">
                                        @if ($exception->first_seen) <x-monitor::relative-time :at="$exception->first_seen"/> @endif
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $exception->last_seen?->diffForHumans() }}">
                                        @if ($exception->last_seen) <x-monitor::relative-time :at="$exception->last_seen"/> @endif
                                    </td>
                                    <td class="py-2.5 pl-2 text-right">
                                        <a href="{{ route('monitor.issues.show', $exception->uuid) }}"
                                           class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-blue-600 dark:group-hover:text-blue-400 group-hover:shadow-sm">
                                            <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $total), 'total' => number_format($total)])"/>
                @endif
            </x-monitor::card>
        @elseif ($view === 'performance' && $performance->isNotEmpty())
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="w-8 pb-2">
                                    <input type="checkbox" x-data="{ pagePairs: {{ Js::from($pagePairs) }} }"
                                           :checked="allSelectedOnPage(pagePairs)"
                                           @click="allSelectedOnPage(pagePairs) ? clear() : selectAll(pagePairs)">
                                </th>
                                <th class="w-12 cursor-pointer select-none pb-2 font-normal" wire:click="sort('id')">
                                    <span class="inline-flex items-center gap-1">
                                        #
                                        <x-monitor::sort-caret field="id" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="w-8 cursor-pointer select-none pb-2 pr-4 font-normal" wire:click="sort('priority')">
                                    <span class="inline-flex items-center gap-1" title="{{ __('monitor::messages.issue.sort_by_priority') }}">
                                        <x-monitor::priority-bars priority="none" class="{{ $sortBy === 'priority' ? 'opacity-100' : 'opacity-40' }}"/>
                                        <x-monitor::sort-caret field="priority" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 font-normal" wire:click="sort('label')">
                                    <span class="inline-flex items-center gap-1">
                                        {{ __('monitor::messages.common.issue') }}
                                        <x-monitor::sort-caret field="label" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 text-right font-normal" wire:click="sort('count')">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        {{ __('monitor::messages.common.count') }}
                                        <x-monitor::sort-caret field="count" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 text-right font-normal" wire:click="sort('max_duration')">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        {{ __('monitor::messages.common.max') }}
                                        <x-monitor::sort-caret field="max_duration" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="cursor-pointer select-none pb-2 text-right font-normal" wire:click="sort('last_seen')">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        {{ __('monitor::messages.common.last_seen') }}
                                        <x-monitor::sort-caret field="last_seen" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                    </span>
                                </th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($performance as $item)
                                <tr wire:key="issue-{{ $item->issue_type }}-{{ $item->key }}" class="group hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="py-2.5 pr-2">
                                        <input type="checkbox" :checked="isSelected({{ Js::from($item->issue_type) }}, {{ Js::from($item->key) }})"
                                               @click="toggle({{ Js::from($item->issue_type) }}, {{ Js::from($item->key) }})">
                                    </td>
                                    <td class="py-2.5 pr-2 font-mono text-xs text-neutral-400 dark:text-neutral-500">#{{ $item->id }}</td>
                                    <td class="py-2.5 pr-4">
                                        <x-monitor::issues.priority-picker :type="$item->issue_type" :issue-key="$item->key" :priority="$item->priority"/>
                                    </td>
                                    <td class="max-w-[26rem] cursor-pointer py-2.5 pr-3" onclick="window.location='{{ route('monitor.issues.show', $item->uuid) }}'">
                                        <span class="mr-2 shrink-0 rounded border border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $item->badge }}</span>
                                        <span class="font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $item->label }}</span>
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ number_format($item->count) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-amber-600 dark:text-amber-400">{{ $fmt($item->max_duration) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $item->last_seen?->diffForHumans() }}">
                                        @if ($item->last_seen) <x-monitor::relative-time :at="$item->last_seen"/> @endif
                                    </td>
                                    <td class="py-2.5 pl-2 text-right">
                                        <a href="{{ route('monitor.issues.show', $item->uuid) }}"
                                           class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-blue-600 dark:group-hover:text-blue-400 group-hover:shadow-sm">
                                            <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $total), 'total' => number_format($total)])"/>
                @endif
            </x-monitor::card>
        @else
            <x-monitor::card class="relative overflow-hidden p-4">
                <p class="select-none break-all font-mono text-xs leading-6 text-neutral-200" aria-hidden="true">{{ $glitch }}</p>
                <span class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 bg-amber-200 px-1.5 py-0.5 font-mono text-xs tracking-tight text-neutral-900">{{ __('monitor::messages.issue.no_issues_found') }}</span>
            </x-monitor::card>
        @endif
    </div>
</div>
