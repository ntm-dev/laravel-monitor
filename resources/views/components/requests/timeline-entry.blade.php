{{-- TimelineEntry: one bar on the timeline, positioned by percentage of the
     shared total-duration scale. Color is looked up by `variant` (a
     lifecycle phase name or a TimelineEntry type) so new event types only
     need a new row in this map. Must render inside the Alpine scope set up
     by timeline.blade.php (hoverId/selectedId/data). --}}
@props(['entryId', 'left', 'width', 'variant' => 'default', 'top' => 0])
@php
    $colors = [
        'root' => 'bg-neutral-800 dark:bg-neutral-200',
        'bootstrap' => 'bg-neutral-400 dark:bg-neutral-500',
        'middleware' => 'bg-sky-400 dark:bg-sky-500',
        'controller' => 'bg-blue-600 dark:bg-blue-400',
        'render' => 'bg-violet-500 dark:bg-violet-400',
        'sending' => 'bg-neutral-400 dark:bg-neutral-500',
        'terminating' => 'bg-neutral-300 dark:bg-neutral-600',
        'query' => 'bg-amber-500 dark:bg-amber-400',
        'cache' => 'bg-emerald-500 dark:bg-emerald-400',
        'mail' => 'bg-pink-500 dark:bg-pink-400',
        'notification' => 'bg-fuchsia-500 dark:bg-fuchsia-400',
        'queue' => 'bg-orange-500 dark:bg-orange-400',
        'http' => 'bg-cyan-500 dark:bg-cyan-400',
    ];

    $phases = ['root', 'bootstrap', 'middleware', 'controller', 'render', 'sending', 'terminating'];

    $color = $colors[$variant] ?? 'bg-neutral-400 dark:bg-neutral-500';
    $height = in_array($variant, $phases, true) ? 'h-4' : 'h-2.5';
@endphp
<div class="group absolute {{ $height }} rounded-sm {{ $color }} cursor-pointer transition-[filter] hover:brightness-110"
     :class="{ 'ring-2 ring-blue-500 ring-offset-1 dark:ring-offset-neutral-900': selectedId === '{{ $entryId }}' }"
     style="left: {{ $left }}%; width: {{ $width }}%; top: {{ $top }}px"
     @mouseenter="hoverId = '{{ $entryId }}'" @mouseleave="hoverId = null"
     @click="selectedId = (selectedId === '{{ $entryId }}' ? null : '{{ $entryId }}')">
    <x-monitor::requests.timeline-tooltip :entry-id="$entryId"/>
</div>
