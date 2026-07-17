{{-- Sticky page header: detail breadcrumb or page title, period switcher with
     custom range picker, and the mobile tab strip. All data is prepared by
     Http\Controllers\DashboardController. --}}
@props(['tab', 'tabs', 'groups', 'title', 'detail', 'key', 'range', 'period', 'periods', 'hasCustomRange', 'from', 'to', 'timezone', 'rangeMax'])
<header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
    <div class="mx-auto flex w-full max-w-[1600px] items-center justify-between gap-4 px-4 py-5 md:px-8">
        @if ($detail !== null)
            <div class="min-w-0">
                <a href="{{ route('monitor.dashboard', ['tab' => $tab] + $range) }}" class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">{{ $tabs[$tab]['label'] }}</a>
                @if ($detail->badge !== null || $detail->heading !== null)
                    <div class="mt-0.5 flex min-w-0 gap-2.5 {{ $detail->wrap ? 'items-start' : 'items-center' }}">
                        @if ($detail->badge !== null)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $detail->badgeClass }}">{{ $detail->badge }}</span>
                        @endif
                        @if ($detail->heading !== null)
                            <h1 class="{{ $detail->wrap ? 'whitespace-pre-wrap break-words font-mono text-base font-semibold' : 'truncate text-2xl font-bold' }} tracking-tight" @if ($detail->titleAttr) title="{{ $detail->titleAttr }}" @endif>{{ $detail->heading }}</h1>
                        @endif
                    </div>
                @endif
            </div>
        @else
            <h1 class="truncate text-2xl font-bold tracking-tight">{{ $title }}</h1>
        @endif

        @if ($tab !== 'settings')
        <div class="flex h-8 shrink-0 items-center gap-0.5 rounded-lg border border-neutral-200 bg-white p-0.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($periods as $value)
                <a href="{{ route('monitor.dashboard', array_filter(['tab' => $tab, 'period' => $value, 'key' => $key])) }}"
                   @class([
                       'flex h-full min-w-8 items-center justify-center rounded-md border px-2.5 font-mono text-xs',
                       'border-blue-500 bg-blue-600 text-white' => ! $hasCustomRange && $period === $value,
                       'border-transparent text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' => $hasCustomRange || $period !== $value,
                   ])>{{ strtoupper($value) }}</a>
            @endforeach
            <span class="mx-0.5 h-4 w-px bg-neutral-200 dark:bg-neutral-700"></span>
            <div x-data="{
                    open: false,
                    mode: 'utc',
                    from: '{{ $from }}',
                    to: '{{ $to }}',
                    error: '',
                    apply() {
                        if (! this.from || ! this.to) { this.error = 'Pick both dates.'; return; }
                        const now = new Date();
                        if (new Date(this.to) > now) { this.to = now.toISOString().slice(0, 16); }
                        if (new Date(this.from) >= new Date(this.to)) { this.error = 'Start must be before end.'; return; }
                        const params = new URLSearchParams({ tab: '{{ $tab }}', from: this.from, to: this.to });
                        @if (filled($key)) params.set('key', @js($key)); @endif
                        window.location = '{{ route('monitor.dashboard') }}?' + params.toString();
                    },
                 }" class="relative h-full">
                <button type="button" @click="open = ! open"
                        @class([
                            'flex h-full items-center gap-1 rounded-md border px-2',
                            'border-blue-500 bg-blue-600 text-white' => $hasCustomRange,
                            'border-transparent text-neutral-400 hover:text-neutral-900 dark:text-neutral-500 dark:hover:text-neutral-100' => ! $hasCustomRange,
                        ])>
                    @if ($hasCustomRange)
                        <span class="font-mono text-xs">{{ $from }} → {{ $to }}</span>
                    @endif
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CALENDAR" class="h-4 w-4"/>
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3 w-3"/>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false"
                     class="absolute right-0 top-full z-30 mt-2 w-64 rounded-lg bg-neutral-900 p-3 shadow-xl shadow-black/20">
                    <div class="grid grid-cols-2 gap-0.5 rounded-md bg-neutral-800 p-0.5 font-mono text-xs">
                        <button type="button" @click="mode = 'utc'" class="rounded px-2 py-1.5" :class="mode === 'utc' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">{{ $timezone }}</button>
                        <button type="button" @click="mode = 'local'" class="rounded px-2 py-1.5" :class="mode === 'local' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">LOCAL</button>
                    </div>
                    <label class="mt-3 block text-xs text-neutral-400">Starting date</label>
                    <input type="datetime-local" x-model="from" max="{{ $rangeMax }}"
                           class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-800 px-2 py-1.5 font-mono text-xs text-neutral-200 focus:outline-none">
                    <label class="mt-3 block text-xs text-neutral-400">Ending date</label>
                    <input type="datetime-local" x-model="to" max="{{ $rangeMax }}"
                           class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-800 px-2 py-1.5 font-mono text-xs text-neutral-200 focus:outline-none">
                    <p x-show="error" x-text="error" class="mt-2 text-xs text-rose-400"></p>
                    <button type="button" @click="apply()"
                            class="mt-3 w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Apply</button>
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
                       'shrink-0 rounded-md border px-2.5 py-1.5',
                       'border-neutral-200 bg-white text-neutral-900 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100' => $tab === $tabKey,
                       'border-transparent text-neutral-500 dark:text-neutral-400' => $tab !== $tabKey,
                   ])>{{ $item['label'] }}</a>
            @endforeach
        @endforeach
    </nav>
</header>
