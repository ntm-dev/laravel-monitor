{{-- TimelineRow: one lifecycle lane (its own bar, when $showBar) plus every
     event entry attributed to it, stacked into non-overlapping sub-lanes
     (Support\Timeline::assignLanes) so concurrent events stay readable. All
     bars share the same left/width % scale as the rest of the timeline. --}}
@props(['label', 'entryId', 'left' => 0, 'width' => 0, 'variant' => 'phase', 'children' => null, 'leftPct' => null, 'widthPct' => null, 'showBar' => true])
@php
    $children = $children ?? collect();
    $laneHeight = 16;
    $barHeight = 22;
    $maxLane = $children->max('lane');
    $trackHeight = max(28, ($showBar ? $barHeight : 6) + ($maxLane !== null ? ($maxLane + 1) * $laneHeight : 0));
@endphp
<div class="grid grid-cols-[10rem_1fr] border-b border-neutral-50 last:border-b-0 dark:border-neutral-800/60">
    <div class="flex items-center px-3 py-1.5 font-mono text-[11px] text-neutral-500 dark:text-neutral-400">{{ $label }}</div>
    <div class="relative py-1.5" style="min-height: {{ $trackHeight }}px">
        @if ($showBar)
            <x-monitor::requests.timeline-entry :entry-id="$entryId" :left="$left" :width="$width" :variant="$variant" :top="0"/>
        @endif

        @foreach ($children as $child)
            <x-monitor::requests.timeline-entry
                :entry-id="$child->id"
                :left="$leftPct($child->start)"
                :width="$widthPct($child->duration)"
                :variant="$child->type"
                :top="($showBar ? $barHeight : 4) + $child->lane * $laneHeight"/>
        @endforeach
    </div>
</div>
