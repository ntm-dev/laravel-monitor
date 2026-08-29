{{-- Horizontally-scrolling chart pane. overflow-y-hidden is required, not
     decorative: overflow-x-auto alone makes the browser treat overflow-y as
     auto too, and each row's invisible hover-tooltip <div> (position:
     absolute; top-full) extends past this box's edges — without it this
     becomes a real (if invisible) vertical scroll container that traps the
     mouse wheel instead of letting it scroll the page. --}}
@props(['rows'])
<div class="relative flex-1 overflow-x-auto overflow-y-hidden bg-neutral-50/50 dark:bg-transparent"
    x-ref="scrollArea" @scroll="scrollLeft = $event.target.scrollLeft" @mousemove.window="onDrag($event)"
    @mouseup.window="stopDrag()">
    <div x-ref="rowsInner" :style="'width: ' + (zoom * 100) + '%'" class="min-w-full select-none"
        :class="dragging ? 'cursor-grabbing' : 'cursor-grab'" @mousedown="startDrag($event)">
        {{-- Rows + full-height gridlines/crosshair overlay --}}
        <div class="relative" x-ref="rows" @mousemove="track($event)" @mouseleave="crossX = null">
            {{-- Vertical gridlines aligned to the ruler ticks. --}}
            <div class="pointer-events-none absolute inset-0 z-0">
                <template x-for="(tick, index) in ticks" :key="index">
                    <div x-show="!tick.first" class="absolute inset-y-0 border-l border-neutral-100 dark:border-neutral-800/70"
                        :style="'left: ' + tick.pct + '%'"></div>
                </template>
            </div>

            {{-- Hover crosshair --}}
            <div x-show="crossX !== null" x-cloak
                class="pointer-events-none absolute inset-y-0 z-10 border-l border-blue-400/60 dark:border-blue-500/60"
                :style="'left: ' + crossX + 'px'"></div>

            @foreach ($rows as $row)
                @if ($row['kind'] === 'divider')
                    <div x-show="expandedTracks['{{ $row['track'] }}']"
                        class="h-9 border-t border-neutral-50 dark:border-neutral-800/40"></div>
                @else
                    <x-monitor::requests.timeline-row :entry="$row['entry']" :left="$row['left']" :width="$row['width']"
                        :kind="$row['kind']" :track-id="$row['track']" :root-label="$row['rootLabel']" :focusable="$row['focusable']"
                        :attempt="$row['attempt']" :job-status="$row['jobStatus']" :attempts-duration="$row['attemptsDuration']"
                        :job-url="$row['jobUrl']" part="bar" />
                @endif
            @endforeach
        </div>
    </div>
</div>
