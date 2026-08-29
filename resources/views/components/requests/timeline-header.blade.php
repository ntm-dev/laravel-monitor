{{-- Sticky header: title/zoom slider (matches the tree pane's width) +
     ruler ticks/hover readout (matches the chart pane's width). z-20 (not
     the z-10 used elsewhere in the card) so it stays above the rows pane's
     own sticky bar labels, and needs its own opaque background since
     sticky content doesn't get one for free. Offset measured at runtime by
     measureHeaderOffsets() (see timeline-script.blade.php) rather than a
     fixed Tailwind class — the three pages sharing this component don't
     all render the same page-header height. --}}
<div x-ref="stickyHeader" :style="'top: ' + pageHeaderOffset + 'px'"
    class="sticky z-20 flex items-stretch divide-x divide-neutral-200 border-b border-neutral-100 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
    <div class="flex w-1/5 max-w-[250px] shrink-0 items-center justify-between gap-3 px-4 py-3">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.timeline') }}</h2>
        <div class="flex items-center gap-1.5">
            {{-- Log-scale track (see zoomSliderValue()/setZoomFromSlider() in
                 timeline-script.blade.php): 0-1, not minZoom-maxZoom(), so
                 equal physical drag distance is equal *percentage* zoom
                 change everywhere on the track instead of equal absolute
                 units — a huge, jarring jump per pixel once zoom itself is
                 already in the hundreds. --}}
            <input type="range" min="0" max="1" step="0.001" :value="zoomSliderValue()"
                @input="setZoomFromSlider($event.target.valueAsNumber)"
                class="h-1.5 w-14 cursor-pointer appearance-none rounded-full bg-neutral-200 accent-neutral-700 dark:bg-neutral-700 dark:accent-neutral-300" />
            <span class="w-8 text-right font-mono text-[10px] text-neutral-500 dark:text-neutral-400"
                x-text="zoom.toFixed(1) + 'x'"></span>
        </div>
    </div>
    <div class="relative flex-1 overflow-hidden">
        <div class="relative h-full"
            :style="'width: ' + (zoom * 100) + '%; transform: translateX(-' + scrollLeft + 'px)'">
            {{-- First/last ticks stay edge-anchored (centering would push
                 half the label off the pane); every other tick centers on
                 its mark to line up with the crosshair. --}}
            <template x-for="(tick, index) in ticks" :key="index">
                <span class="absolute top-1 font-mono text-[10px] text-neutral-400 dark:text-neutral-500"
                    :class="tick.first ? 'pl-1' : (tick.last ? '-translate-x-full pr-1' : '-translate-x-1/2')"
                    :style="'left: ' + tick.pct + '%'" x-text="tick.label"></span>
            </template>

            {{-- Hover ms readout, tracking the rows pane's own crosshair
                 (same crossX, same zoom-width + translateX transform).
                 Pinned to the ruler's bottom edge, coloured to match the
                 crosshair line. formatTickMs() (not a rounded 'Xms' string)
                 keeps this legible once 1ms already spans many px. --}}
            <div x-show="crossX !== null" x-cloak
                class="pointer-events-none absolute bottom-0 z-10 -translate-x-1/2 whitespace-nowrap rounded-t bg-blue-500 px-1.5 py-0.5 font-mono text-[10px] text-white shadow dark:bg-blue-600"
                :style="'left: ' + crossX + 'px'" x-text="formatTickMs(crossMs(), crossStepMs())"></div>
        </div>
    </div>

    {{-- Mirrors the detail panel's own w-80 spacer below: the panel is a
         flex sibling of the chart pane, so opening it shrinks that pane by
         320px — without a matching spacer here the two rows' panes would
         end up different widths. --}}
    <div x-show="selectedId !== null" x-cloak class="w-80 shrink-0"></div>
</div>
