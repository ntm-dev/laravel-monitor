{{-- Sticky header: title/zoom slider over the tree pane, ruler over the chart pane. z-20 keeps it
     above the sticky bar labels; its top offset comes from measureHeaderOffsets(). --}}
<div x-ref="stickyHeader" :style="'top: ' + pageHeaderOffset + 'px'"
    class="sticky z-20 flex items-stretch divide-x divide-neutral-200 border-b border-neutral-100 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
    <div class="flex w-1/5 max-w-[250px] shrink-0 items-center justify-between gap-3 px-4 py-3">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.timeline') }}</h2>
        <div class="flex items-center gap-1.5">
            {{-- Log-scale 0-1 track (zoomSliderValue()/setZoomFromSlider()): equal drag = equal percentage zoom. --}}
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

            {{-- Hover ms readout following the rows pane's crosshair (same crossX and transform). --}}
            <div x-show="crossX !== null" x-cloak
                class="pointer-events-none absolute bottom-0 z-10 -translate-x-1/2 whitespace-nowrap rounded-t bg-blue-500 px-1.5 py-0.5 font-mono text-[10px] text-white shadow dark:bg-blue-600"
                :style="'left: ' + crossX + 'px'" x-text="formatTickMs(crossMs(), crossStepMs())"></div>
        </div>
    </div>

    {{-- Matches the open detail panel's w-80 so the header and rows panes stay the same width. --}}
    <div x-show="selectedId !== null" x-cloak class="w-80 shrink-0"></div>
</div>
