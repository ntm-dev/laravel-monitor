{{-- Desktop sidebar: app identity, grouped tab links and footer entries.
     All data is prepared by Http\Controllers\DashboardController. --}}
{{-- autoRefreshes: false on the standalone, non-Livewire detail pages (Request
     Detail's timeline, Job Attempt, Command Run, Schedule Run, Issue Detail) —
     those own their own URL and render everything once server-side, no
     wire:poll anywhere on the page, so the active tab's refresh ring would be
     showing a countdown to a refresh that never happens. --}}
@props(['groups', 'footerTabs', 'tab', 'range', 'refresh', 'appInitial', 'autoRefreshes' => true])
@php
    $navActor = request()->user(\LaravelMonitor\Models\MonitorUser::guardName());
@endphp
{{-- collapsed mirrors the localStorage key the pre-paint script in
     components/layout.blade.php reads, so the initial Alpine state always
     matches what was already rendered before hydration — no flash. --}}
<aside x-data="{ collapsed: localStorage.getItem('monitor-nav-collapsed') === '1' }"
       :class="{ 'monitor-nav-collapsed': collapsed }"
       class="monitor-nav-aside sticky top-0 hidden h-screen shrink-0 flex-col border-r border-neutral-200 bg-white md:flex dark:border-neutral-800 dark:bg-neutral-900">
    <div class="p-2">
        {{-- Collapsed: name hides (.monitor-nav-label), leaving just the app
             icon and the toggle button — flex-col stacks them instead of
             the row overflowing the narrow rail. --}}
        <div class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5" :class="{ 'flex-col': collapsed }">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ $appInitial }}</span>
            <span class="monitor-nav-label min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold leading-tight">{{ config('app.name', 'Laravel') }}</span>
                <span class="block text-xs leading-tight text-neutral-500 dark:text-neutral-400">{{ ucfirst(app()->environment()) }}</span>
            </span>
            <button type="button"
                    @click="collapsed = ! collapsed; localStorage.setItem('monitor-nav-collapsed', collapsed ? '1' : '0')"
                    :data-tooltip="collapsed ? @js(__('monitor::messages.nav.expand')) : @js(__('monitor::messages.nav.collapse'))"
                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-neutral-400 hover:bg-neutral-100 hover:text-neutral-900 dark:text-neutral-500 dark:hover:bg-neutral-800 dark:hover:text-neutral-100">
                <span class="flex h-4 w-4 shrink-0 transition-transform" :class="{ 'rotate-180': collapsed }">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOUBLE_LEFT" class="h-4 w-4 shrink-0"/>
                </span>
                <span class="sr-only">{{ __('monitor::messages.nav.collapse') }}</span>
            </button>
        </div>
    </div>

    <nav class="monitor-nav-scrollbar flex-1 overflow-y-auto px-2 pb-2">
        @foreach ($groups as $group => $items)
            @if ($group !== '')
                <p class="monitor-nav-label px-2 pb-1 pt-4 text-xs text-neutral-400 dark:text-neutral-500">{{ $group }}</p>
            @endif
            <div class="space-y-px">
                @foreach ($items as $tabKey => $item)
                    <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                       data-tooltip="{{ $item['label'] }}"
                       @class([
                           'group flex h-9 w-full items-center gap-3 rounded-md border px-2 text-sm',
                           'border-neutral-200 bg-white text-neutral-900 shadow-lg shadow-black/5 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100' => $tab === $tabKey,
                           'border-transparent text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' => $tab !== $tabKey,
                       ])>
                        <x-monitor::icon :path="$item['icon']" class="h-4 w-4 shrink-0 {{ $tab === $tabKey ? 'text-blue-600 dark:text-blue-400' : 'text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300' }}"/>
                        <span class="monitor-nav-label flex-1 truncate">{{ $item['label'] }}</span>
                        @if ($tabKey === 'issues')
                            <span class="monitor-nav-label">
                                @livewire('monitor.open-issue-badge', key('nav-open-issue-badge'))
                            </span>
                        @endif
                        @if ($tab === $tabKey && $autoRefreshes)
                            {{-- Only the active tab actually has a wire:poll running — the
                                 others are plain links, nothing there is really refreshing. --}}
                            <span class="monitor-nav-label">
                                <x-monitor::refresh-ring :refresh="$refresh"/>
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    <div class="p-2">
        <div class="space-y-px pb-2">
            @foreach ($footerTabs as $tabKey => $item)
                <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                   data-tooltip="{{ $item['label'] }}"
                   @class([
                       'group flex h-9 w-full items-center gap-3 rounded-md border px-2 text-sm',
                       'border-neutral-200 bg-white text-neutral-900 shadow-lg shadow-black/5 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100' => $tab === $tabKey,
                       'border-transparent text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' => $tab !== $tabKey,
                   ])>
                    <x-monitor::icon :path="$item['icon']" class="h-4 w-4 shrink-0 {{ $tab === $tabKey ? 'text-blue-600 dark:text-blue-400' : 'text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300' }}"/>
                    <span class="monitor-nav-label flex-1 truncate">{{ $item['label'] }}</span>
                    @if ($tab === $tabKey && $tabKey !== 'settings' && $autoRefreshes)
                        {{-- Settings has no wire:poll — it's a static config form,
                             nothing there refreshes even while active. --}}
                        <span class="monitor-nav-label">
                            <x-monitor::refresh-ring :refresh="$refresh"/>
                        </span>
                    @endif
                </a>
            @endforeach
            <a href="https://github.com/ntm-dev/laravel-monitor" target="_blank" rel="noopener"
               data-tooltip="{{ __('monitor::messages.nav.support') }}"
               class="group flex h-9 w-full items-center gap-3 rounded-md border border-transparent px-2 text-sm text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::SUPPORT" class="h-4 w-4 shrink-0 text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300"/>
                <span class="monitor-nav-label">{{ __('monitor::messages.nav.support') }}</span>
            </a>
        </div>
        <div class="flex items-center gap-2.5 border-t border-neutral-100 px-2 pb-1 pt-2.5 dark:border-neutral-800">
            <span class="monitor-nav-label flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ strtoupper(mb_substr($navActor?->name ?? $appInitial, 0, 1)) }}</span>
            <span class="monitor-nav-label truncate text-sm text-neutral-700 dark:text-neutral-300">{{ $navActor?->name ?? config('app.name', 'Laravel') }}</span>
            <span class="monitor-nav-label ml-auto flex items-center gap-1.5 text-xs text-neutral-400 dark:text-neutral-500" data-tooltip="{{ __('monitor::messages.nav.live_refresh', ['seconds' => $refresh]) }}">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>
            </span>
            {{-- Always visible, even collapsed — sign out is a functional
                 action, not identity chrome, so it doesn't hide with the rest
                 of this row. --}}
            <form method="POST" action="{{ route('monitor.logout') }}">
                @csrf
                <button type="submit" data-tooltip="{{ __('monitor::messages.nav.sign_out') }}"
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-neutral-400 hover:bg-neutral-100 hover:text-neutral-900 dark:text-neutral-500 dark:hover:bg-neutral-800 dark:hover:text-neutral-100">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::SIGN_OUT" class="h-4 w-4 shrink-0"/>
                    <span class="sr-only">{{ __('monitor::messages.nav.sign_out') }}</span>
                </button>
            </form>
        </div>
    </div>

    {{-- Same dark-mode scrollbar fix as the Request Detail timeline (see
         components/requests/timeline.blade.php's .monitor-timeline-scrollbar)
         — without it this vertical scrollbar stays the light native OS
         colour even inside a dark-mode page. --}}
    <style>
        .monitor-nav-scrollbar {
            scrollbar-color: rgb(212 212 212) transparent;
        }
        .dark .monitor-nav-scrollbar {
            scrollbar-color: rgb(64 64 64) transparent;
        }
        .monitor-nav-scrollbar::-webkit-scrollbar {
            width: 8px;
        }
        .monitor-nav-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .monitor-nav-scrollbar::-webkit-scrollbar-thumb {
            background-color: rgb(212 212 212);
            border-radius: 9999px;
        }
        .dark .monitor-nav-scrollbar::-webkit-scrollbar-thumb {
            background-color: rgb(64 64 64);
        }
    </style>
</aside>
