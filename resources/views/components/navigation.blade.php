{{-- Desktop sidebar: app identity, grouped tab links and footer entries.
     All data is prepared by Http\Controllers\DashboardController. --}}
@props(['groups', 'footerTabs', 'tab', 'range', 'refresh', 'appInitial', 'openIssueCount' => 0])
@php
    $navActor = request()->user(\LaravelMonitor\Models\MonitorUser::guardName());
@endphp
<aside class="sticky top-0 hidden h-screen w-[228px] shrink-0 flex-col bg-neutral-200 shadow-neu-sm md:flex dark:bg-neutral-800 dark:shadow-neu-dark-sm">
    <div class="p-2">
        <div class="flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700 dark:shadow-neu-dark-sm">{{ $appInitial }}</span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold leading-tight">{{ config('app.name', 'Laravel') }}</span>
                <span class="block text-xs leading-tight text-neutral-500 dark:text-neutral-400">{{ ucfirst(app()->environment()) }}</span>
            </span>
        </div>
    </div>

    <nav class="monitor-nav-scrollbar flex-1 overflow-y-auto px-2 pb-2">
        @foreach ($groups as $group => $items)
            @if ($group !== '')
                <p class="px-2 pb-1 pt-4 text-xs text-neutral-400 dark:text-neutral-500">{{ $group }}</p>
            @endif
            <div class="space-y-px">
                @foreach ($items as $tabKey => $item)
                    <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                       @class([
                           'group flex h-9 w-full items-center gap-3 rounded-xl px-2 text-sm transition-shadow',
                           'text-neutral-900 shadow-neu-inset dark:text-neutral-100 dark:shadow-neu-dark-inset' => $tab === $tabKey,
                           'text-neutral-500 hover:text-neutral-900 hover:shadow-neu-sm dark:text-neutral-400 dark:hover:text-neutral-100 dark:hover:shadow-neu-dark-sm' => $tab !== $tabKey,
                       ])>
                        <x-monitor::icon :path="$item['icon']" class="h-4 w-4 shrink-0 {{ $tab === $tabKey ? 'text-blue-600 dark:text-purple-400' : 'text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300' }}"/>
                        <span class="flex-1 truncate">{{ $item['label'] }}</span>
                        @if ($tabKey === 'issues' && $openIssueCount > 0)
                            <span class="shrink-0 rounded-full bg-neutral-200 px-1.5 py-0.5 font-mono text-[10px] leading-none text-rose-600 shadow-neu-inset dark:bg-neutral-800 dark:text-rose-400 dark:shadow-neu-dark-inset">{{ $openIssueCount > 99 ? '99+' : $openIssueCount }}</span>
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
                   @class([
                       'group flex h-9 w-full items-center gap-3 rounded-xl px-2 text-sm transition-shadow',
                       'text-neutral-900 shadow-neu-inset dark:text-neutral-100 dark:shadow-neu-dark-inset' => $tab === $tabKey,
                       'text-neutral-500 hover:text-neutral-900 hover:shadow-neu-sm dark:text-neutral-400 dark:hover:text-neutral-100 dark:hover:shadow-neu-dark-sm' => $tab !== $tabKey,
                   ])>
                    <x-monitor::icon :path="$item['icon']" class="h-4 w-4 shrink-0 {{ $tab === $tabKey ? 'text-blue-600 dark:text-purple-400' : 'text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300' }}"/>
                    {{ $item['label'] }}
                </a>
            @endforeach
            <a href="https://github.com/ntm-dev/laravel-monitor" target="_blank" rel="noopener"
               class="group flex h-9 w-full items-center gap-3 rounded-xl px-2 text-sm text-neutral-500 hover:text-neutral-900 hover:shadow-neu-sm dark:text-neutral-400 dark:hover:text-neutral-100 dark:hover:shadow-neu-dark-sm">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::SUPPORT" class="h-4 w-4 shrink-0 text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300"/>
                {{ __('monitor::messages.nav.support') }}
            </a>
        </div>
        <div class="flex items-center gap-2.5 px-2 pb-1 pt-2.5 shadow-[0_-1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_-1px_0_rgba(255,255,255,0.06)]">
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-neutral-200 text-xs font-semibold text-neutral-600 shadow-neu-sm dark:bg-neutral-800 dark:text-neutral-300 dark:shadow-neu-dark-sm">{{ strtoupper(mb_substr($navActor?->name ?? $appInitial, 0, 1)) }}</span>
            <span class="truncate text-sm text-neutral-700 dark:text-neutral-300">{{ $navActor?->name ?? config('app.name', 'Laravel') }}</span>
            <span class="ml-auto flex items-center gap-1.5 text-xs text-neutral-400 dark:text-neutral-500" title="{{ __('monitor::messages.nav.live_refresh', ['seconds' => $refresh]) }}">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>
            </span>
            <form method="POST" action="{{ route('monitor.logout') }}">
                @csrf
                <button type="submit" title="{{ __('monitor::messages.nav.sign_out') }}"
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-neutral-400 hover:text-neutral-900 hover:shadow-neu-sm dark:text-neutral-500 dark:hover:text-neutral-100 dark:hover:shadow-neu-dark-sm">
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
