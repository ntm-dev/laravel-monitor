{{-- Timeline: Nightwatch-style waterfall of the request lifecycle. Two-pane
     layout — a pinned tree column on the left (divided from the chart by a
     vertical border) and a shared time chart on the right with a ruler,
     vertical gridlines, inline bar labels, a hover crosshair and
     click-to-inspect. All data is precomputed by
     View\Components\Requests\Timeline; this template only renders it. --}}
<x-monitor::card class="overflow-hidden p-0"
     x-data="{
         zoom: 1,
         minZoom: 1,
         maxZoom: 8,
         crossX: null,
         selectedId: null,
         dragging: false,
         dragMoved: false,
         dragStartX: 0,
         dragScrollStart: 0,
         data: {!! $entriesJson !!},
         selected() { return this.selectedId !== null ? this.data[this.selectedId] : null },
         track(event) {
             const rect = this.$refs.rows.getBoundingClientRect();
             const x = event.clientX - rect.left;
             this.crossX = x > 256 ? x : null;
         },
         setZoom(next) {
             const el = this.$refs.scrollArea;
             const oldWidth = el.scrollWidth;
             const ratio = oldWidth > 0 ? el.scrollLeft / oldWidth : 0;
             this.zoom = Math.min(this.maxZoom, Math.max(this.minZoom, next));
             this.$nextTick(() => { el.scrollLeft = ratio * el.scrollWidth; });
         },
         startDrag(event) {
             if (event.button !== 0) return;
             this.dragging = true;
             this.dragMoved = false;
             this.dragStartX = event.clientX;
             this.dragScrollStart = this.$refs.scrollArea.scrollLeft;
         },
         onDrag(event) {
             if (! this.dragging) return;
             const dx = event.clientX - this.dragStartX;
             if (Math.abs(dx) > 3) this.dragMoved = true;
             this.$refs.scrollArea.scrollLeft = this.dragScrollStart - dx;
         },
         stopDrag() { this.dragging = false },
         selectRow(id) { if (! this.dragMoved) this.selectedId = (this.selectedId === id ? null : id) },
     }">
    <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">Timeline</h2>
        <div class="flex items-center gap-2.5">
            <span class="font-mono text-[10px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">Zoom</span>
            <input type="range" :min="minZoom" :max="maxZoom" step="0.1" :value="zoom"
                   @input="setZoom($event.target.valueAsNumber)"
                   class="h-1.5 w-28 cursor-pointer appearance-none rounded-full bg-neutral-200 accent-neutral-700 dark:bg-neutral-700 dark:accent-neutral-300"/>
            <span class="w-9 text-right font-mono text-[11px] text-neutral-500 dark:text-neutral-400" x-text="zoom.toFixed(1) + 'x'"></span>
        </div>
    </div>

    <div class="overflow-x-auto" x-ref="scrollArea" @mousemove.window="onDrag($event)" @mouseup.window="stopDrag()">
        <div :style="'width: ' + (zoom * 100) + '%'" class="min-w-full select-none" :class="dragging ? 'cursor-grabbing' : 'cursor-grab'" @mousedown="startDrag($event)">
            {{-- Ruler --}}
            <div class="grid grid-cols-[16rem_1fr] border-b border-neutral-100 dark:border-neutral-800">
                <div class="sticky left-0 z-20 border-r border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"></div>
                <div class="relative h-6 overflow-hidden">
                    @foreach ($ticks as $tick)
                        <span class="absolute top-1 font-mono text-[10px] text-neutral-400 dark:text-neutral-500 {{ $tick['last'] ? '-translate-x-full pr-1' : 'pl-1' }}"
                              style="left: {{ $tick['pct'] }}%">{{ $tick['label'] }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Rows + full-height gridlines/crosshair overlay --}}
            <div class="relative" x-ref="rows" @mousemove="track($event)" @mouseleave="crossX = null">
                {{-- Vertical gridlines aligned to the ruler ticks (chart pane only) --}}
                <div class="pointer-events-none absolute inset-y-0 left-[16rem] right-0 z-0">
                    @foreach ($ticks as $tick)
                        @unless ($tick['first'])
                            <div class="absolute inset-y-0 border-l border-neutral-100 dark:border-neutral-800/70" style="left: {{ $tick['pct'] }}%"></div>
                        @endunless
                    @endforeach
                </div>

                {{-- Hover crosshair --}}
                <div x-show="crossX !== null" x-cloak
                     class="pointer-events-none absolute inset-y-0 z-10 border-l border-blue-400/60 dark:border-blue-500/60"
                     :style="'left: ' + crossX + 'px'"></div>

                @foreach ($rows as $row)
                    <x-monitor::requests.timeline-row :entry="$row['entry']" :kind="$row['kind']" :total="$totalDuration"/>
                @endforeach

                {{-- Events that didn't fall inside any recorded phase --}}
                @if ($orphanRows !== [])
                    <div class="grid grid-cols-[16rem_1fr] border-b border-neutral-50 dark:border-neutral-800/40">
                        <div class="sticky left-0 z-20 border-r border-neutral-200 bg-white px-3 py-1.5 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">Other</div>
                        <div></div>
                    </div>
                    @foreach ($orphanRows as $row)
                        <x-monitor::requests.timeline-row :entry="$row['entry']" :kind="$row['kind']" :total="$totalDuration"/>
                    @endforeach
                @endif
            </div>
        </div>
    </div>

    {{-- Selected event detail panel --}}
    <div x-show="selectedId !== null" x-cloak x-transition
         class="border-t border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-900/50">
        <div class="flex items-center justify-between">
            <div class="flex items-baseline gap-2">
                <h3 class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400" x-text="selected()?.label"></h3>
                <span class="font-mono text-xs text-neutral-400 dark:text-neutral-500"
                      x-text="selected() ? (selected().start + 'ms → ' + (selected().start + selected().duration) + 'ms · ' + selected().duration + 'ms') : ''"></span>
            </div>
            <button type="button" @click="selectedId = null" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOSE" :stroke="2" class="h-4 w-4"/>
            </button>
        </div>
        <dl class="mt-2 space-y-1">
            <template x-for="[key, value] in Object.entries(selected()?.metadata || {})" :key="key">
                <div class="flex items-start gap-3 py-0.5 text-xs">
                    <dt class="w-32 shrink-0 text-neutral-500 dark:text-neutral-400" x-text="key"></dt>
                    <dd class="min-w-0 flex-1 whitespace-pre-wrap break-words font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="typeof value === 'object' ? JSON.stringify(value) : value"></dd>
                </div>
            </template>
        </dl>
    </div>
</x-monitor::card>
