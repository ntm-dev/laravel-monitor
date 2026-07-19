@php
    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $glitch = collect(range(1, 60))->map(fn ($i) => strtoupper(base_convert(md5('nightwatch'.$i), 16, 36)))->implode(' ');
    $actionButton = 'shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50 hover:text-neutral-900 dark:hover:text-neutral-100';
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex h-9 items-center gap-0.5 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-0.5 shadow-sm">
            @foreach (['exceptions' => ['Exceptions', $exceptionCount], 'performance' => ['Performance', $performanceCount]] as $issueTab => [$issueLabel, $issueCount])
                <button type="button" wire:click="$set('view', '{{ $issueTab }}')"
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
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::SEARCH" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search"
                       class="h-9 w-52 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-3 text-sm text-neutral-700 dark:text-neutral-200 shadow-sm placeholder:text-neutral-400 dark:placeholder:text-neutral-500 focus:outline-none">
            </div>
            <div class="flex h-9 items-center gap-0.5 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-0.5 text-sm shadow-sm">
                @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $statusKey => $statusLabel)
                    <button type="button" wire:click="$set('status', '{{ $statusKey }}')"
                            @class([
                                'flex h-full items-center rounded-md border px-3',
                                'border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 text-neutral-900 dark:text-neutral-100' => $status === $statusKey,
                                'border-transparent text-neutral-400 dark:text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100' => $status !== $statusKey,
                            ])>{{ $statusLabel }}</button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-4">
        @if ($view === 'exceptions' && $exceptions->isNotEmpty())
            <x-monitor::card>
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($exceptions as $exception)
                        <div class="flex items-center gap-2 p-3.5 hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <a href="{{ route('monitor.dashboard', ['tab' => 'exceptions'] + $range) }}" class="block min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="break-all font-mono text-xs font-medium text-rose-600 dark:text-rose-400">{{ class_basename($exception->latest['class'] ?? $exception->key) }}</p>
                                    <span class="shrink-0 rounded border border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 px-1.5 py-0.5 font-mono text-xs text-rose-600 dark:text-rose-400">{{ number_format($exception->count) }}×</span>
                                </div>
                                @if (($exception->latest['message'] ?? '') !== '')
                                    <p class="mt-1 line-clamp-2 text-xs text-neutral-500 dark:text-neutral-400">{{ $exception->latest['message'] }}</p>
                                @endif
                                <div class="mt-1.5 flex items-center gap-3 font-mono text-[11px] text-neutral-400 dark:text-neutral-500">
                                    @if (isset($exception->latest['file']))
                                        <span class="truncate">{{ $exception->latest['file'] }}:{{ $exception->latest['line'] ?? '' }}</span>
                                    @endif
                                    <span class="ml-auto shrink-0" title="First seen {{ $exception->first_seen?->diffForHumans() }}">{{ $exception->last_seen?->diffForHumans(short: true) }}</span>
                                </div>
                            </a>
                            <div class="flex shrink-0 items-center gap-1">
                                @if ($exception->status === 'open')
                                    <button type="button" wire:click="resolve('exception', '{{ $exception->key }}')" class="{{ $actionButton }}">Resolve</button>
                                    <button type="button" wire:click="ignore('exception', '{{ $exception->key }}')" class="{{ $actionButton }}">Ignore</button>
                                @else
                                    <button type="button" wire:click="reopen('exception', '{{ $exception->key }}')" class="{{ $actionButton }}">Reopen</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @elseif ($view === 'performance' && $performance->isNotEmpty())
            <x-monitor::card>
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($performance as $item)
                        <div class="flex items-center gap-2 p-3.5 hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <a href="{{ route('monitor.dashboard', ['tab' => $item->tab] + (in_array($item->type, ['request', 'job'], true) ? ['key' => $item->key] : []) + $range) }}"
                               class="flex min-w-0 flex-1 items-center justify-between gap-3">
                                <span class="flex min-w-0 items-center gap-2.5">
                                    <span class="shrink-0 rounded border border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $item->badge }}</span>
                                    <span class="truncate font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $item->label }}</span>
                                </span>
                                <span class="flex shrink-0 items-center gap-4 font-mono text-xs text-neutral-400 dark:text-neutral-500" title="First seen {{ $item->first_seen?->diffForHumans() }}">
                                    <span>{{ number_format($item->count) }}×</span>
                                    <span>MAX <span class="text-amber-600 dark:text-amber-400">{{ $fmt($item->max_duration) }}</span></span>
                                </span>
                            </a>
                            <div class="flex shrink-0 items-center gap-1">
                                @if ($item->status === 'open')
                                    <button type="button" wire:click="resolve('{{ $item->issue_type }}', '{{ $item->key }}')" class="{{ $actionButton }}">Resolve</button>
                                    <button type="button" wire:click="ignore('{{ $item->issue_type }}', '{{ $item->key }}')" class="{{ $actionButton }}">Ignore</button>
                                @else
                                    <button type="button" wire:click="reopen('{{ $item->issue_type }}', '{{ $item->key }}')" class="{{ $actionButton }}">Reopen</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @else
            <x-monitor::card class="relative overflow-hidden p-4">
                <p class="select-none break-all font-mono text-xs leading-6 text-neutral-200" aria-hidden="true">{{ $glitch }}</p>
                <span class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 bg-amber-200 px-1.5 py-0.5 font-mono text-xs tracking-tight text-neutral-900">NO ISSUES FOUND</span>
            </x-monitor::card>
        @endif
    </div>
</div>
