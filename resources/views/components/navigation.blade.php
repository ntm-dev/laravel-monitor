{{-- Desktop sidebar: app identity, grouped tab links and footer entries.
     All data is prepared by Http\Controllers\DashboardController. --}}
@props(['groups', 'footerTabs', 'tab', 'range', 'refresh', 'appInitial', 'openIssueCount' => 0])
<aside class="sticky top-0 hidden h-screen w-[228px] shrink-0 flex-col border-r border-neutral-200 bg-white md:flex dark:border-neutral-800 dark:bg-neutral-900">
    <div class="p-2">
        <div class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-700">{{ $appInitial }}</span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold leading-tight">{{ config('app.name', 'Laravel') }}</span>
                <span class="block text-xs leading-tight text-neutral-500 dark:text-neutral-400">{{ ucfirst(app()->environment()) }}</span>
            </span>
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_UP_DOWN" class="h-4 w-4 shrink-0 text-neutral-400 dark:text-neutral-500"/>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto px-2 pb-2">
        @foreach ($groups as $group => $items)
            @if ($group !== '')
                <p class="px-2 pb-1 pt-4 text-xs text-neutral-400 dark:text-neutral-500">{{ $group }}</p>
            @endif
            <div class="space-y-px">
                @foreach ($items as $tabKey => $item)
                    <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                       @class([
                           'group flex h-9 w-full items-center gap-3 rounded-md border px-2 text-sm',
                           'border-neutral-200 bg-white text-neutral-900 shadow-lg shadow-black/5 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100' => $tab === $tabKey,
                           'border-transparent text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' => $tab !== $tabKey,
                       ])>
                        <x-monitor::icon :path="$item['icon']" class="h-4 w-4 shrink-0 {{ $tab === $tabKey ? 'text-blue-600 dark:text-blue-400' : 'text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300' }}"/>
                        <span class="flex-1 truncate">{{ $item['label'] }}</span>
                        @if ($tabKey === 'issues' && $openIssueCount > 0)
                            <span class="shrink-0 rounded-full border border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 px-1.5 py-0.5 font-mono text-[10px] leading-none text-rose-600 dark:text-rose-400">{{ $openIssueCount > 99 ? '99+' : $openIssueCount }}</span>
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
                       'group flex h-9 w-full items-center gap-3 rounded-md border px-2 text-sm',
                       'border-neutral-200 bg-white text-neutral-900 shadow-lg shadow-black/5' => $tab === $tabKey,
                       'border-transparent text-neutral-500 hover:text-neutral-900' => $tab !== $tabKey,
                   ])>
                    <x-monitor::icon :path="$item['icon']" class="h-4 w-4 shrink-0 {{ $tab === $tabKey ? 'text-blue-600' : 'text-neutral-400 group-hover:text-neutral-600' }}"/>
                    {{ $item['label'] }}
                </a>
            @endforeach
            <a href="https://github.com/ntm-dev/laravel-monitor" target="_blank" rel="noopener"
               class="group flex h-9 w-full items-center gap-3 rounded-md border border-transparent px-2 text-sm text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::SUPPORT" class="h-4 w-4 shrink-0 text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300"/>
                {{ __('monitor::messages.nav.support') }}
            </a>
        </div>
        <div class="flex items-center gap-2.5 border-t border-neutral-100 px-2 pb-1 pt-2.5 dark:border-neutral-800">
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ $appInitial }}</span>
            <span class="truncate text-sm text-neutral-700 dark:text-neutral-300">{{ config('app.name', 'Laravel') }}</span>
            <span class="ml-auto flex items-center gap-1.5 text-xs text-neutral-400 dark:text-neutral-500" title="Live · refreshes every {{ $refresh }}s">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>
            </span>
        </div>
    </div>
</aside>
