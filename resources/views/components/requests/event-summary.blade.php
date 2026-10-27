{{-- One card per recorder type with a count + total duration, from $summary. --}}
@props(['summary'])
@php
    use LaravelMonitor\Support\Icons;

    // 'type' is the TimelineEntry::$type a click scrolls the timeline to.
    $cards = [
        'queries' => ['label' => __('monitor::messages.nav.queries'), 'icon' => Icons::QUERIES],
        'cache' => ['label' => __('monitor::messages.nav.cache'), 'icon' => Icons::CACHE, 'type' => 'cache'],
        'mail' => ['label' => __('monitor::messages.nav.mail'), 'icon' => Icons::MAIL, 'type' => 'mail'],
        'notifications' => ['label' => __('monitor::messages.nav.notifications'), 'icon' => Icons::NOTIFICATIONS, 'type' => 'notification'],
        'jobs' => ['label' => __('monitor::messages.common.queued_jobs'), 'icon' => Icons::JOBS, 'type' => 'queue'],
        'outgoing' => ['label' => __('monitor::messages.nav.outgoing'), 'icon' => Icons::OUTGOING, 'type' => 'http'],
        'lazy_loading' => ['label' => __('monitor::messages.common.lazy_loads'), 'icon' => Icons::EXCEPTIONS, 'type' => 'lazy_loading'],
    ];

    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
@endphp
{{-- start event summary cards --}}
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
    @foreach ($cards as $key => $card)
        @php($stats = $summary[$key] ?? ['count' => 0, 'duration' => 0])
        @php($clickable = $key !== 'queries' && $stats['count'] > 0)
        {{-- Bound :onclick is omitted when null; kept in a variable since nested quotes inline
             break the component tag's attribute parsing. --}}
        @php($onclick = $clickable ? "window.dispatchEvent(new CustomEvent('monitor-scroll-to-timeline-event', { detail: { type: '{$card['type']}' } }))" : null)
        <x-monitor::card
            class="p-3 {{ $clickable ? 'cursor-pointer transition hover:border-neutral-300 dark:hover:border-neutral-700' : '' }}"
            :onclick="$onclick"
        >
            <div class="flex items-center gap-1.5 text-neutral-500 dark:text-neutral-400">
                <x-monitor::icon :path="$card['icon']" :stroke="1.8" class="h-3.5 w-3.5"/>
                <span class="font-mono text-[11px] uppercase tracking-tight">{{ $card['label'] }}</span>
            </div>
            <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($stats['count']) }}</p>
            <div class="mt-0.5 flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5">
                <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $stats['duration'] > 0 ? $fmt($stats['duration']) : '—' }}</span>
                @if ($key === 'queries' && ($stats['duplicates'] ?? 0) > 0)
                    {{-- Pulses the timeline's duplicate-SQL dots (monitor-duplicates-heartbeat event). --}}
                    <span class="cursor-pointer font-mono text-[11px] text-amber-600 hover:underline dark:text-amber-400"
                          onclick="window.dispatchEvent(new CustomEvent('monitor-duplicates-heartbeat'))">{{ $stats['duplicates'] }} {{ trans_choice('monitor::messages.common.duplicate_count', $stats['duplicates']) }}</span>
                @endif
            </div>
        </x-monitor::card>
    @endforeach
</div>
{{-- end event summary cards --}}
