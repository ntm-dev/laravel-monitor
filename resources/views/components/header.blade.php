{{-- Sticky page header: detail breadcrumb or page title, period switcher with
     custom range picker, and the mobile tab strip. All data is prepared by
     Http\Controllers\DashboardController. Optional slots: $leading renders
     before the page title, $periodsExtra renders in the period switcher's
     spot (alongside or instead of it). --}}
@props(['tab', 'tabs', 'groups', 'title', 'detail', 'key', 'range', 'period', 'periods', 'hasCustomRange', 'from', 'to', 'timezone', 'rangeMax', 'currentRouteName', 'currentRouteParams'])
@php
    // $timezone is a display offset ("+07:00"); the zone identifier comes from Preferences.
    $timezoneIdentifier = \LaravelMonitor\Support\Preferences::timezone();
    // The picker dedupes its timezone tabs by current offset, not identifier.
    $timezoneOffsetSeconds = (new \DateTimeZone($timezoneIdentifier))->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
    $timezoneOffsetMinutes = intdiv($timezoneOffsetSeconds, 60);
    // e.g. "Asia/Ho Chi Minh (UTC+7)", same format as Preferences::timezoneOptions().
    $timezoneLabel = str_replace('_', ' ', $timezoneIdentifier).' ('.\LaravelMonitor\Support\Preferences::formatOffset($timezoneOffsetSeconds).')';
@endphp
<header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
    <div class="mx-auto flex w-full max-w-[1600px] items-center justify-between gap-4 px-4 py-5 md:px-8">
        @if ($detail !== null)
            <div class="min-w-0">
                <nav class="flex min-w-0 items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                    <a href="{{ route('monitor.dashboard', ['tab' => $tab] + $range) }}" class="shrink-0 hover:text-neutral-900 dark:hover:text-neutral-100">{{ $tabs[$tab]['label'] }}</a>
                    @if ($detail->breadcrumbLabel !== null)
                        <span class="shrink-0">›</span>
                        <a href="{{ route($detail->breadcrumbRouteName, $detail->breadcrumbRouteParams + $range) }}"
                           class="min-w-0 truncate hover:text-neutral-900 dark:hover:text-neutral-100">{{ $detail->breadcrumbLabel }}</a>
                    @endif
                </nav>
                @if ($detail->badge !== null || $detail->heading !== null)
                    <div class="mt-0.5 flex min-w-0 gap-2.5 {{ $detail->wrap ? 'items-start' : 'items-center' }}">
                        @if (! $detail->badgeAfter && $detail->badge !== null)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $detail->badgeClass }}">{{ $detail->badge }}</span>
                        @endif
                        @if ($detail->secondaryBadge !== null)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs {{ $detail->badgeClass }}">{{ $detail->secondaryBadge }}</span>
                        @endif
                        @if ($detail->heading !== null)
                            <h1 class="{{ $detail->wrap ? 'whitespace-pre-wrap break-words font-mono text-base font-semibold' : 'truncate text-2xl font-bold' }} tracking-tight" @if ($detail->titleAttr) data-tooltip="{{ $detail->titleAttr }}" @endif>{{ $detail->heading }}</h1>
                        @endif
                        @if ($detail->badgeAfter && $detail->badge !== null)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $detail->badgeClass }}">{{ $detail->badge }}</span>
                        @endif
                    </div>
                @endif
                @if ($detail->subtitle !== null)
                    {{-- start header subtitle --}}
                    <div class="mt-1 flex items-center gap-1.5" x-data="{ copied: false }">
                        <p class="truncate font-mono text-xs text-neutral-500 dark:text-neutral-400" data-tooltip="{{ $detail->subtitle }}">{{ $detail->subtitle }}</p>
                        <button type="button"
                                @click="navigator.clipboard.writeText(@js($detail->subtitle)); copied = true; setTimeout(() => copied = false, 1500)"
                                class="shrink-0 text-neutral-400 hover:text-neutral-700 dark:text-neutral-500 dark:hover:text-neutral-200">
                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5" x-show="! copied"/>
                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="copied" x-cloak/>
                        </button>
                    </div>
                    {{-- end header subtitle --}}
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

        @if (! in_array($tab, ['settings', 'issues'], true) && ($detail?->showPeriodSwitcher ?? true))
        {{-- start period session memory --}}
        {{-- Remembers the picked period for this browser tab and restores it on a URL that carries none. --}}
        <script>
            (() => {
                try {
                    const periods = @js($periods);
                    const url = new URL(location.href);
                    const current = url.searchParams.get('period');

                    if (current) {
                        if (periods.includes(current)) {
                            sessionStorage.setItem('monitor.period', current);
                        }

                        return;
                    }

                    const saved = sessionStorage.getItem('monitor.period');

                    if (saved && saved !== @js(\LaravelMonitor\Livewire\Card::DEFAULT_PERIOD) && periods.includes(saved)
                        && ! url.searchParams.has('from') && ! url.searchParams.has('to')) {
                        url.searchParams.set('period', saved);
                        location.replace(url);
                    }
                } catch (e) {}
            })();
        </script>
        {{-- end period session memory --}}
        <div class="flex h-8 shrink-0 items-center gap-0.5 rounded-lg border border-neutral-200 bg-white p-0.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($periods as $value)
                <a href="{{ route($currentRouteName, $currentRouteParams + array_filter(['period' => $value])) }}"
                   @class([
                       'flex h-full min-w-8 items-center justify-center rounded-md border px-2.5 font-mono text-xs',
                       'border-blue-500 bg-blue-600 text-white' => ! $hasCustomRange && $period === $value,
                       'border-transparent text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' => $hasCustomRange || $period !== $value,
                   ])>{{ strtoupper($value) }}</a>
            @endforeach
            <span class="picker mx-0.5 h-4 w-px bg-neutral-200 dark:bg-neutral-700"></span>
            {{-- The custom-range UI lives entirely in datetime-picker.blade.php. --}}
            <x-monitor::datetime-picker :from="$from" :to="$to" :timezone-offset-minutes="$timezoneOffsetMinutes"
                :timezone-label="$timezoneLabel" :has-custom-range="$hasCustomRange"
                :current-route-name="$currentRouteName" :current-route-params="$currentRouteParams"/>
        </div>
        @endif
    </div>

    {{-- Mobile navigation --}}
    <nav class="flex gap-1 overflow-x-auto px-4 pb-2 text-xs md:hidden">
        @foreach ($groups as $items)
            @foreach ($items as $tabKey => $item)
                <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                   @class([
                       'shrink-0 rounded-md border px-2.5 py-1.5',
                       'border-neutral-200 bg-white text-neutral-900 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100' => $tab === $tabKey,
                       'border-transparent text-neutral-500 dark:text-neutral-400' => $tab !== $tabKey,
                   ])>{{ $item['label'] }}</a>
            @endforeach
        @endforeach
    </nav>
</header>
