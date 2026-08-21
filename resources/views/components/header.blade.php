{{-- Sticky page header: detail breadcrumb or page title, period switcher with
     custom range picker, and the mobile tab strip. All data is prepared by
     Http\Controllers\DashboardController. Optional slots: $leading renders
     before the page title, $periodsExtra renders in the period switcher's
     spot (alongside or instead of it). --}}
@props(['tab', 'tabs', 'groups', 'title', 'detail', 'key', 'range', 'period', 'periods', 'hasCustomRange', 'from', 'to', 'timezone', 'rangeMax', 'currentRouteName', 'currentRouteParams'])
<header class="sticky top-0 z-10 bg-neutral-200/80 backdrop-blur dark:bg-neutral-800/80">
    <div class="mx-auto flex w-full max-w-[1600px] items-center justify-between gap-4 px-4 py-5 md:px-8">
        @if ($detail !== null)
            <div class="min-w-0">
                <a href="{{ route('monitor.dashboard', ['tab' => $tab] + $range) }}" class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">{{ $tabs[$tab]['label'] }}</a>
                @if ($detail->badge !== null || $detail->heading !== null)
                    <div class="mt-0.5 flex min-w-0 gap-2.5 {{ $detail->wrap ? 'items-start' : 'items-center' }}">
                        @if (! $detail->badgeAfter && $detail->badge !== null)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $detail->badgeClass }}">{{ $detail->badge }}</span>
                        @endif
                        @if ($detail->heading !== null)
                            <h1 class="{{ $detail->wrap ? 'whitespace-pre-wrap break-words font-mono text-base font-semibold' : 'truncate text-2xl font-bold' }} tracking-tight" @if ($detail->titleAttr) title="{{ $detail->titleAttr }}" @endif>{{ $detail->heading }}</h1>
                        @endif
                        @if ($detail->badgeAfter && $detail->badge !== null)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $detail->badgeClass }}">{{ $detail->badge }}</span>
                        @endif
                    </div>
                @endif
            </div>
        @else
            <div class="flex min-w-0 items-center gap-2.5">
                @isset($leading)
                    {{ $leading }}
                @endisset
                <h1 class="truncate text-2xl font-bold tracking-tight">{{ $title }}</h1>
            </div>
        @endif

        @if (! in_array($tab, ['settings', 'team'], true))
        <div class="flex h-8 shrink-0 items-center gap-0.5 rounded-xl bg-neutral-200 p-0.5 shadow-neu-inset dark:bg-neutral-800 dark:shadow-neu-dark-inset">
            @foreach ($periods as $value)
                <a href="{{ route($currentRouteName, $currentRouteParams + array_filter(['period' => $value])) }}"
                   @class([
                       'flex h-full min-w-8 items-center justify-center rounded-lg px-2.5 font-mono text-xs',
                       'bg-blue-600 dark:bg-purple-600 text-white shadow-neu-sm' => ! $hasCustomRange && $period === $value,
                       'text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' => $hasCustomRange || $period !== $value,
                   ])>{{ strtoupper($value) }}</a>
            @endforeach
            <span class="mx-0.5 h-4 w-px bg-neutral-300 dark:bg-neutral-700"></span>
            <div x-data="{
                    open: false,
                    mode: 'utc',
                    from: '{{ $from }}',
                    to: '{{ $to }}',
                    error: '',
                    apply() {
                        if (! this.from || ! this.to) { this.error = @js(__('monitor::messages.header.pick_both_dates')); return; }
                        const now = new Date();
                        if (new Date(this.to) > now) { this.to = now.toISOString().slice(0, 16); }
                        if (new Date(this.from) >= new Date(this.to)) { this.error = @js(__('monitor::messages.header.start_before_end')); return; }
                        const params = new URLSearchParams({ from: this.from, to: this.to });
                        window.location = '{{ route($currentRouteName, $currentRouteParams) }}?' + params.toString();
                    },
                 }" class="relative h-full">
                <button type="button" @click="open = ! open"
                        @class([
                            'flex h-full items-center gap-1 rounded-lg px-2',
                            'bg-blue-600 dark:bg-purple-600 text-white shadow-neu-sm' => $hasCustomRange,
                            'text-neutral-400 hover:text-neutral-900 dark:text-neutral-500 dark:hover:text-neutral-100' => ! $hasCustomRange,
                        ])>
                    @if ($hasCustomRange)
                        <span class="font-mono text-xs">{{ $from }} → {{ $to }}</span>
                    @endif
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CALENDAR" class="h-4 w-4"/>
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3 w-3"/>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false"
                     class="absolute right-0 top-full z-30 mt-2 w-64 rounded-2xl bg-neutral-200 p-3 shadow-neu-lg dark:bg-neutral-800 dark:shadow-neu-dark-lg">
                    <div class="grid grid-cols-2 gap-0.5 rounded-xl bg-neutral-200 p-0.5 font-mono text-xs shadow-neu-inset dark:bg-neutral-800 dark:shadow-neu-dark-inset">
                        <button type="button" @click="mode = 'utc'" class="rounded-lg px-2 py-1.5" :class="mode === 'utc' ? 'bg-neutral-200 text-neutral-900 shadow-neu-sm dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-sm' : 'text-neutral-500 dark:text-neutral-400'">{{ $timezone }}</button>
                        <button type="button" @click="mode = 'local'" class="rounded-lg px-2 py-1.5" :class="mode === 'local' ? 'bg-neutral-200 text-neutral-900 shadow-neu-sm dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-sm' : 'text-neutral-500 dark:text-neutral-400'">{{ __('monitor::messages.header.timezone_local') }}</button>
                    </div>
                    <label class="mt-3 block text-xs text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.header.starting_date') }}</label>
                    <input type="datetime-local" x-model="from" max="{{ $rangeMax }}"
                           class="mt-1 w-full rounded-lg bg-neutral-200 px-2 py-1.5 font-mono text-xs text-neutral-800 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-200 dark:shadow-neu-dark-inset">
                    <label class="mt-3 block text-xs text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.header.ending_date') }}</label>
                    <input type="datetime-local" x-model="to" max="{{ $rangeMax }}"
                           class="mt-1 w-full rounded-lg bg-neutral-200 px-2 py-1.5 font-mono text-xs text-neutral-800 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-200 dark:shadow-neu-dark-inset">
                    <p x-show="error" x-text="error" class="mt-2 text-xs text-rose-500 dark:text-rose-400"></p>
                    <button type="button" @click="apply()"
                            class="mt-3 w-full rounded-lg bg-blue-600 dark:bg-purple-600 py-2 text-sm font-medium text-white shadow-neu-sm hover:bg-blue-500 dark:hover:bg-purple-500 active:scale-[0.98]">{{ __('monitor::messages.header.apply') }}</button>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Mobile navigation --}}
    <nav class="flex gap-1 overflow-x-auto px-4 pb-2 text-xs md:hidden">
        @foreach ($groups as $items)
            @foreach ($items as $tabKey => $item)
                <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                   @class([
                       'shrink-0 rounded-lg px-2.5 py-1.5 transition-shadow',
                       'bg-neutral-200 text-neutral-900 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-100 dark:shadow-neu-dark-inset' => $tab === $tabKey,
                       'text-neutral-500 hover:text-neutral-900 hover:shadow-neu-sm dark:text-neutral-400 dark:hover:text-neutral-100 dark:hover:shadow-neu-dark-sm' => $tab !== $tabKey,
                   ])>{{ $item['label'] }}</a>
            @endforeach
        @endforeach
    </nav>
</header>
