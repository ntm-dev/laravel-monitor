{{-- Sticky page header: detail breadcrumb or page title, period switcher with
     custom range picker, and the mobile tab strip. All data is prepared by
     Http\Controllers\DashboardController. Optional slots: $leading renders
     before the page title, $periodsExtra renders in the period switcher's
     spot (alongside or instead of it). --}}
@props(['tab', 'tabs', 'groups', 'title', 'detail', 'key', 'range', 'period', 'periods', 'hasCustomRange', 'from', 'to', 'timezone', 'rangeMax', 'currentRouteName', 'currentRouteParams'])
@php
    // $timezone (the prop above) is Format::timezone() — already a display
    // offset string like "+07:00", not the zone identifier — no good for
    // either DateTimeZone or a "Asia/Ho_Chi_Minh"-style label below, so this
    // block fetches the real identifier straight from Preferences instead.
    $timezoneIdentifier = \LaravelMonitor\Support\Preferences::timezone();
    // Same computation as Preferences::timezoneOptions() — the custom-range
    // picker's UTC/Local/setting tabs are deduplicated by current UTC
    // offset, not by zone identifier: two identifiers can share the same
    // offset right now (e.g. Asia/Bangkok and Asia/Ho_Chi_Minh are both
    // UTC+7 with no DST), and comparing names would then show two tabs that
    // enter the exact same wall-clock values.
    $timezoneOffsetSeconds = (new \DateTimeZone($timezoneIdentifier))->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
    $timezoneOffsetMinutes = intdiv($timezoneOffsetSeconds, 60);
    // "Asia/Ho Chi Minh (UTC+7)" — name plus offset, same "name"/"offset"
    // pieces (and the same underscore-to-space cleanup) as Settings' own
    // timezone picker (Preferences::timezoneOptions()).
    $timezoneLabel = str_replace('_', ' ', $timezoneIdentifier).' ('.\LaravelMonitor\Support\Preferences::formatOffset($timezoneOffsetSeconds).')';
@endphp
<header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
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
                            <h1 class="{{ $detail->wrap ? 'whitespace-pre-wrap break-words font-mono text-base font-semibold' : 'truncate text-2xl font-bold' }} tracking-tight" @if ($detail->titleAttr) data-tooltip="{{ $detail->titleAttr }}" @endif>{{ $detail->heading }}</h1>
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

        @if (! in_array($tab, ['settings', 'team', 'issues'], true))
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
            {{-- Trigger button, timezone tabs, from/to fields, and apply
                 button are all self-contained in components/datetime-picker
                 .blade.php's own x-data — this just passes through the
                 range/timezone props already computed above. --}}
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
