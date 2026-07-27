{{-- EventSummary: one card per recorder type with a count + total duration.
     Data-driven off $summary (built by RequestDetailController) — add a
     recorder here to surface it, no other changes needed. Each card with a
     count links to EventListController's "every occurrence of this type,
     for this request/job/command" page via $eventUrls (built by the same
     controllers), keyed the same as $cards. --}}
@props(['summary', 'eventUrls' => []])
@php
    use LaravelMonitor\Support\Icons;

    $cards = [
        'queries' => ['label' => 'Queries', 'icon' => Icons::QUERIES],
        'cache' => ['label' => 'Cache', 'icon' => Icons::CACHE],
        'mail' => ['label' => 'Mail', 'icon' => Icons::MAIL],
        'notifications' => ['label' => 'Notifications', 'icon' => Icons::NOTIFICATIONS],
        'jobs' => ['label' => 'Queued Jobs', 'icon' => Icons::JOBS],
        'outgoing' => ['label' => 'Outgoing Requests', 'icon' => Icons::OUTGOING],
        'lazy_loading' => ['label' => 'Lazy Loads', 'icon' => Icons::EXCEPTIONS],
    ];

    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
@endphp
{{-- start event summary cards --}}
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
    @foreach ($cards as $key => $card)
        @php($stats = $summary[$key] ?? ['count' => 0, 'duration' => 0])
        @php($clickable = $stats['count'] > 0 && isset($eventUrls[$key]))
        <x-monitor::card @class(['p-3', 'cursor-pointer hover:border-neutral-300 dark:hover:border-neutral-700' => $clickable])
            :onclick="$clickable ? 'window.location=\''.$eventUrls[$key].'\'' : null">
            <div class="flex items-center gap-1.5 text-neutral-500 dark:text-neutral-400">
                <x-monitor::icon :path="$card['icon']" :stroke="1.8" class="h-3.5 w-3.5"/>
                <span class="font-mono text-[11px] uppercase tracking-tight">{{ $card['label'] }}</span>
            </div>
            <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($stats['count']) }}</p>
            <div class="mt-0.5 flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5">
                <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $stats['duration'] > 0 ? $fmt($stats['duration']) : '—' }}</span>
                @if ($key === 'queries' && ($stats['duplicates'] ?? 0) > 0)
                    <span class="ml-auto font-mono text-[11px] text-amber-600 dark:text-amber-400">{{ $stats['duplicates'] }} {{ $stats['duplicates'] === 1 ? 'duplicate' : 'duplicates' }}</span>
                @endif
            </div>
        </x-monitor::card>
    @endforeach
</div>
{{-- end event summary cards --}}
