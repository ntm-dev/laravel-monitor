{{-- EventSummary: one card per recorder type with a count + total duration.
     Data-driven off $summary (built by RequestDetailController) — add a
     recorder here to surface it, no other changes needed. --}}
@props(['summary'])
@php
    use LaravelMonitor\Support\Icons;

    $cards = [
        'queries' => ['label' => 'Queries', 'icon' => Icons::QUERIES],
        'cache' => ['label' => 'Cache', 'icon' => Icons::CACHE],
        'mail' => ['label' => 'Mail', 'icon' => Icons::MAIL],
        'notifications' => ['label' => 'Notifications', 'icon' => Icons::NOTIFICATIONS],
        'jobs' => ['label' => 'Queued Jobs', 'icon' => Icons::JOBS],
        'outgoing' => ['label' => 'Outgoing Requests', 'icon' => Icons::OUTGOING],
    ];

    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
@endphp
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
    @foreach ($cards as $key => $card)
        @php($stats = $summary[$key] ?? ['count' => 0, 'duration' => 0])
        <x-monitor::card class="p-3">
            <div class="flex items-center gap-1.5 text-neutral-500 dark:text-neutral-400">
                <x-monitor::icon :path="$card['icon']" :stroke="1.8" class="h-3.5 w-3.5"/>
                <span class="font-mono text-[11px] uppercase tracking-tight">{{ $card['label'] }}</span>
            </div>
            <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($stats['count']) }}</p>
            <p class="mt-0.5 font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $stats['duration'] > 0 ? $fmt($stats['duration']) : '—' }}</p>
        </x-monitor::card>
    @endforeach
</div>
