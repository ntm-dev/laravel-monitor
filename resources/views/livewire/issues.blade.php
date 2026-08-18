@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $glitch = collect(range(1, 60))->map(fn ($i) => strtoupper(base_convert(md5('monitor'.$i), 16, 36)))->implode(' ');
    $actionButton = 'shrink-0 rounded-lg bg-neutral-200 dark:bg-neutral-800 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark hover:text-neutral-900 dark:hover:text-neutral-100';
    $priorityColor = fn (string $priority) => match ($priority) {
        'urgent' => 'text-rose-500',
        'high' => 'text-orange-500',
        'medium' => 'text-amber-500',
        'low' => 'text-blue-500',
        default => 'text-neutral-300 dark:text-neutral-600',
    };
    $selectedCount = array_sum(array_map('count', $selected));
    $rows = $view === 'exceptions' ? $exceptions : $performance;
    $allSelectedOnPage = $rows->isNotEmpty() && $rows->every(
        fn ($row) => isset($selected[$view === 'exceptions' ? 'exception' : $row->issue_type][$row->key])
    );
    $pagePairs = $view === 'exceptions'
        ? $exceptions->map(fn ($e) => ['exception', $e->key])->values()
        : $performance->map(fn ($item) => [$item->issue_type, $item->key])->values();
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex h-9 items-center gap-0.5 rounded-xl bg-neutral-200 dark:bg-neutral-800 p-0.5 shadow-neu-inset dark:shadow-neu-dark-inset">
            @foreach (['exceptions' => [__('monitor::messages.nav.exceptions'), $exceptionCount], 'performance' => [__('monitor::messages.issue.performance'), $performanceCount]] as $issueTab => [$issueLabel, $issueCount])
                <button type="button" wire:click="$set('view', '{{ $issueTab }}')"
                        @class([
                            'flex h-full items-center gap-2 rounded-lg px-3 text-sm',
                            'bg-neutral-200 shadow-neu-sm dark:bg-neutral-800 dark:shadow-neu-dark-sm text-neutral-900 dark:text-neutral-100' => $view === $issueTab,
                            'text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100' => $view !== $issueTab,
                        ])>
                    {{ $issueLabel }}
                    <span class="rounded bg-neutral-200/80 dark:bg-neutral-700/80 px-1.5 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ $issueCount }}</span>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search') }}"
                       class="h-9 w-52 rounded-xl bg-neutral-200 dark:bg-neutral-800 pl-8 pr-3 text-sm text-neutral-700 dark:text-neutral-200 shadow-neu-inset dark:shadow-neu-dark-inset placeholder:text-neutral-400 dark:placeholder:text-neutral-500">
            </div>
            <div class="flex h-9 items-center gap-0.5 rounded-xl bg-neutral-200 dark:bg-neutral-800 p-0.5 text-sm shadow-neu-inset dark:shadow-neu-dark-inset">
                @foreach (['open' => __('monitor::messages.issue.status_open'), 'resolved' => __('monitor::messages.issue.status_resolved'), 'ignored' => __('monitor::messages.issue.status_ignored')] as $statusKey => $statusLabel)
                    <button type="button" wire:click="$set('status', '{{ $statusKey }}')"
                            @class([
                                'flex h-full items-center rounded-lg px-3',
                                'bg-neutral-200 shadow-neu-sm dark:bg-neutral-800 dark:shadow-neu-dark-sm text-neutral-900 dark:text-neutral-100' => $status === $statusKey,
                                'text-neutral-400 dark:text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100' => $status !== $statusKey,
                            ])>{{ $statusLabel }}</button>
                @endforeach
            </div>
        </div>
    </div>

    @if ($selectedCount > 0)
        <div class="mt-3 flex items-center gap-3 rounded-xl bg-neutral-200 dark:bg-neutral-800 px-3 py-2 text-sm shadow-neu-inset dark:shadow-neu-dark-inset">
            <span class="font-medium text-blue-700 dark:text-blue-300">{{ __('monitor::messages.issue.selected', ['count' => $selectedCount]) }}</span>
            <button type="button" wire:click="resolveSelected" class="{{ $actionButton }}">{{ __('monitor::messages.issue.resolve') }}</button>
            <button type="button" wire:click="ignoreSelected" class="{{ $actionButton }}">{{ __('monitor::messages.issue.ignore') }}</button>
            <button type="button" wire:click="deselectAll" class="ml-auto text-xs text-blue-700 dark:text-blue-300 hover:underline">{{ __('monitor::messages.issue.clear') }}</button>
        </div>
    @endif

    <div class="mt-4">
        @if ($view === 'exceptions' && $exceptions->isNotEmpty())
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="w-8 pb-2">
                                    <input type="checkbox" @checked($allSelectedOnPage)
                                           wire:click="{{ $allSelectedOnPage ? 'deselectAll' : 'selectAll' }}({{ $allSelectedOnPage ? '' : Js::from($pagePairs) }})">
                                </th>
                                <th class="w-12 pb-2 font-normal">#</th>
                                <th class="w-8 pb-2 font-normal"></th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.issue') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.count') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.nav.users') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.first_seen') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.last_seen') }}</th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                            @foreach ($exceptions as $exception)
                                <tr class="group rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset">
                                    <td class="py-2.5 pr-2">
                                        <input type="checkbox" @checked(isset($selected['exception'][$exception->key]))
                                               wire:click="toggleSelected('exception', '{{ $exception->key }}')">
                                    </td>
                                    <td class="py-2.5 pr-2 font-mono text-xs text-neutral-400 dark:text-neutral-500">#{{ $exception->id }}</td>
                                    <td class="py-2.5 pr-2" title="{{ Format::priorityLabel($exception->priority) }}">
                                        <x-monitor::icon :path="Icons::PRIORITY" :stroke="2" class="h-4 w-4 {{ $priorityColor($exception->priority) }}"/>
                                    </td>
                                    <td class="max-w-[26rem] cursor-pointer py-2.5 pr-3" onclick="window.location='{{ route('monitor.issues.show', $exception->uuid) }}'">
                                        <p class="truncate font-mono text-xs font-medium text-neutral-800 dark:text-neutral-200" title="{{ $exception->latest['class'] ?? $exception->key }}">{{ $exception->latest['class'] ?? $exception->key }}</p>
                                        @if (($exception->latest['message'] ?? '') !== '')
                                            <p class="mt-0.5 line-clamp-1 text-xs text-neutral-400 dark:text-neutral-500">{{ $exception->latest['message'] }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ number_format($exception->count) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-500 dark:text-neutral-400">—</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $exception->first_seen?->diffForHumans() }}">{{ $exception->first_seen?->diffForHumans(short: true) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $exception->last_seen?->diffForHumans() }}">{{ $exception->last_seen?->diffForHumans(short: true) }}</td>
                                    <td class="py-2.5 pl-2 text-right">
                                        @if ($exception->status === 'open')
                                            <button type="button" wire:click="resolve('exception', '{{ $exception->key }}')" class="{{ $actionButton }}">{{ __('monitor::messages.issue.resolve') }}</button>
                                        @else
                                            <button type="button" wire:click="reopen('exception', '{{ $exception->key }}')" class="{{ $actionButton }}">{{ __('monitor::messages.issue.reopen') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-monitor::card>
        @elseif ($view === 'performance' && $performance->isNotEmpty())
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="w-8 pb-2">
                                    <input type="checkbox" @checked($allSelectedOnPage)
                                           wire:click="{{ $allSelectedOnPage ? 'deselectAll' : 'selectAll' }}({{ $allSelectedOnPage ? '' : Js::from($pagePairs) }})">
                                </th>
                                <th class="w-12 pb-2 font-normal">#</th>
                                <th class="w-8 pb-2 font-normal"></th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.issue') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.count') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.max') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.last_seen') }}</th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                            @foreach ($performance as $item)
                                <tr class="group rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset">
                                    <td class="py-2.5 pr-2">
                                        <input type="checkbox" @checked(isset($selected[$item->issue_type][$item->key]))
                                               wire:click="toggleSelected('{{ $item->issue_type }}', '{{ $item->key }}')">
                                    </td>
                                    <td class="py-2.5 pr-2 font-mono text-xs text-neutral-400 dark:text-neutral-500">#{{ $item->id }}</td>
                                    <td class="py-2.5 pr-2" title="{{ Format::priorityLabel($item->priority) }}">
                                        <x-monitor::icon :path="Icons::PRIORITY" :stroke="2" class="h-4 w-4 {{ $priorityColor($item->priority) }}"/>
                                    </td>
                                    <td class="max-w-[26rem] cursor-pointer py-2.5 pr-3" onclick="window.location='{{ route('monitor.issues.show', $item->uuid) }}'">
                                        <span class="mr-2 shrink-0 rounded border border-neutral-200 dark:border-neutral-700 bg-neutral-200/80 dark:bg-neutral-800/80 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $item->badge }}</span>
                                        <span class="font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $item->label }}</span>
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ number_format($item->count) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-amber-600 dark:text-amber-400">{{ $fmt($item->max_duration) }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" title="{{ $item->last_seen?->diffForHumans() }}">{{ $item->last_seen?->diffForHumans(short: true) }}</td>
                                    <td class="py-2.5 pl-2 text-right">
                                        @if ($item->status === 'open')
                                            <button type="button" wire:click="resolve('{{ $item->issue_type }}', '{{ $item->key }}')" class="{{ $actionButton }}">{{ __('monitor::messages.issue.resolve') }}</button>
                                        @else
                                            <button type="button" wire:click="reopen('{{ $item->issue_type }}', '{{ $item->key }}')" class="{{ $actionButton }}">{{ __('monitor::messages.issue.reopen') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-monitor::card>
        @else
            <x-monitor::card class="relative overflow-hidden p-4">
                <p class="select-none break-all font-mono text-xs leading-6 text-neutral-200" aria-hidden="true">{{ $glitch }}</p>
                <span class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 bg-amber-200 px-1.5 py-0.5 font-mono text-xs tracking-tight text-neutral-900">{{ __('monitor::messages.issue.no_issues_found') }}</span>
            </x-monitor::card>
        @endif
    </div>
</div>
