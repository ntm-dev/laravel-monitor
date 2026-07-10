@php
    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $glitch = collect(range(1, 60))->map(fn ($i) => strtoupper(base_convert(md5('nightwatch'.$i), 16, 36)))->implode(' ');
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex h-9 items-center gap-0.5 rounded-lg border border-neutral-200 bg-white p-0.5 shadow-sm">
            @foreach (['exceptions' => ['Exceptions', $exceptionCount], 'performance' => ['Performance', $slowRouteCount]] as $issueTab => [$issueLabel, $issueCount])
                <button type="button" wire:click="$set('view', '{{ $issueTab }}')"
                        @class([
                            'flex h-full items-center gap-2 rounded-md border px-3 text-sm',
                            'border-neutral-200 bg-neutral-100/80 text-neutral-900' => $view === $issueTab,
                            'border-transparent text-neutral-500 hover:text-neutral-900' => $view !== $issueTab,
                        ])>
                    {{ $issueLabel }}
                    <span class="rounded bg-neutral-200/80 px-1.5 font-mono text-[11px] text-neutral-600">{{ $issueCount }}</span>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <div class="relative">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::SEARCH" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search"
                       class="h-9 w-52 rounded-lg border border-neutral-200 bg-white pl-8 pr-3 text-sm text-neutral-700 shadow-sm placeholder:text-neutral-400 focus:outline-none">
            </div>
            <div class="flex h-9 items-center gap-0.5 rounded-lg border border-neutral-200 bg-white p-0.5 text-sm shadow-sm" title="Issue states are not tracked yet">
                <span class="flex h-full items-center rounded-md border border-neutral-200 bg-neutral-100/80 px-3 text-neutral-900">Open</span>
                <span class="flex h-full items-center rounded-md px-3 text-neutral-400">Resolved</span>
                <span class="flex h-full items-center rounded-md px-3 text-neutral-400">Ignored</span>
            </div>
        </div>
    </div>

    <div class="mt-4">
        @if ($view === 'exceptions' && $exceptions->isNotEmpty())
            <x-monitor::card>
                <div class="divide-y divide-neutral-100">
                    @foreach ($exceptions as $exception)
                        <a href="{{ route('monitor.dashboard', ['tab' => 'exceptions'] + $range) }}" class="block p-3.5 hover:bg-neutral-50">
                            <div class="flex items-start justify-between gap-2">
                                <p class="break-all font-mono text-xs font-medium text-rose-600">{{ class_basename($exception->key) }}</p>
                                <span class="shrink-0 rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 font-mono text-xs text-rose-600">{{ number_format($exception->count) }}×</span>
                            </div>
                            @if (($exception->latest['message'] ?? '') !== '')
                                <p class="mt-1 line-clamp-2 text-xs text-neutral-500">{{ $exception->latest['message'] }}</p>
                            @endif
                            <div class="mt-1.5 flex items-center gap-3 font-mono text-[11px] text-neutral-400">
                                @if (isset($exception->latest['file']))
                                    <span class="truncate">{{ $exception->latest['file'] }}:{{ $exception->latest['line'] ?? '' }}</span>
                                @endif
                                <span class="ml-auto shrink-0">{{ $exception->last_seen?->diffForHumans(short: true) }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </x-monitor::card>
        @elseif ($view === 'performance' && $slowRoutes->isNotEmpty())
            <x-monitor::card>
                <div class="divide-y divide-neutral-100">
                    @foreach ($slowRoutes as $route)
                        <a href="{{ route('monitor.dashboard', ['tab' => 'requests', 'key' => $route->key] + $range) }}"
                           class="flex items-center justify-between gap-3 p-3.5 hover:bg-neutral-50">
                            <span class="min-w-0">
                                <span class="block font-mono text-[11px] uppercase tracking-tight text-neutral-400">{{ \Illuminate\Support\Str::before($route->key, ' ') }}</span>
                                <span class="block truncate font-mono text-xs text-neutral-700">{{ \Illuminate\Support\Str::after($route->key, ' ') }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-4 font-mono text-xs text-neutral-400">
                                <span>{{ number_format($route->count) }}×</span>
                                <span>MAX <span class="text-amber-600">{{ $fmt($route->max_duration) }}</span></span>
                            </span>
                        </a>
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
