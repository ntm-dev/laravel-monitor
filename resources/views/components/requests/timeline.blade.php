{{-- Timeline: Nightwatch-style waterfall of the request lifecycle. True
     two-pane layout — a pinned tree pane on the left and an independently
     horizontally-scrolling chart pane on the right, as two separate flex
     siblings (not a shared scrolling grid). The tree pane never joins the
     chart's horizontal scroll, so it needs no sticky-positioning hacks.

     The header mirrors the same width split as the panes below: the left
     box holds the "Timeline" title + zoom slider (matching the tree pane's
     width), the right box holds the ruler ticks (matching the chart pane's
     width). Since the ruler lives in the header, outside the scrolling
     pane, its ticks are kept in sync with panning/zooming via a manual
     transform driven by the chart's own scrollLeft (`scrollLeft` below) —
     there's no native scroll here to piggyback on.

     `isolate` is required, not decorative: the chart pane's inline bar
     labels are `position: sticky; z-10` (to stay visible during horizontal
     scroll), and with no ancestor between them and <body> creating its own
     stacking context, that z-10 ties with the dashboard's own sticky page
     header (also z-10) — a tie the later element in the DOM (this card)
     wins, painting the timeline over the page header once scrolled. Giving
     the card its own stacking context contains every z-index inside it, so
     the page header (outside this context) always wins instead. All data is
     precomputed by View\Components\Requests\Timeline; this template only
     renders it. --}}
<x-monitor::card class="isolate overflow-hidden p-0"
     x-data="{
         zoom: 1,
         minZoom: 1,
         maxZoom: 8,
         scrollLeft: 0,
         crossX: null,
         selectedId: null,
         hoveredId: null,
         dragging: false,
         dragMoved: false,
         dragStartX: 0,
         dragScrollStart: 0,
         data: {!! $entriesJson !!},
         selected() { return this.selectedId !== null ? this.data[this.selectedId] : null },
         selectedTimestamp() {
             const iso = this.selected()?.metadata?.created_at;
             return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'medium' }) : '';
         },
         sqlIsWrite() {
             const sql = (this.selected()?.metadata?.sql || '').trim().toLowerCase();
             return sql !== '' && ! sql.startsWith('select');
         },
         sqlHighlighted() {
             const sql = this.selected()?.metadata?.sql;
             if (! sql) return '';
             return window.hljs ? window.hljs.highlight(sql, { language: 'sql', ignoreIllegals: true }).value : sql;
         },
         copySql() {
             const sql = this.selected()?.metadata?.sql;
             if (sql) navigator.clipboard.writeText(sql);
         },
         track(event) {
             const rect = this.$refs.rows.getBoundingClientRect();
             this.crossX = event.clientX - rect.left;
         },
         setZoom(next) {
             const el = this.$refs.scrollArea;
             const oldWidth = el.scrollWidth;
             const ratio = oldWidth > 0 ? el.scrollLeft / oldWidth : 0;
             this.zoom = Math.min(this.maxZoom, Math.max(this.minZoom, next));
             this.$nextTick(() => { el.scrollLeft = ratio * el.scrollWidth; this.scrollLeft = el.scrollLeft; });
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
    <div class="flex items-stretch divide-x divide-neutral-200 border-b border-neutral-100 dark:divide-neutral-800 dark:border-neutral-800">
        <div class="flex w-1/5 max-w-[250px] shrink-0 items-center justify-between gap-3 px-4 py-3">
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">Timeline</h2>
            <div class="flex items-center gap-1.5">
                <input type="range" :min="minZoom" :max="maxZoom" step="0.1" :value="zoom"
                       @input="setZoom($event.target.valueAsNumber)"
                       class="h-1.5 w-14 cursor-pointer appearance-none rounded-full bg-neutral-200 accent-neutral-700 dark:bg-neutral-700 dark:accent-neutral-300"/>
                <span class="w-8 text-right font-mono text-[10px] text-neutral-500 dark:text-neutral-400" x-text="zoom.toFixed(1) + 'x'"></span>
            </div>
        </div>
        <div class="relative flex-1 overflow-hidden">
            <div class="relative h-full" :style="'width: ' + (zoom * 100) + '%; transform: translateX(-' + scrollLeft + 'px)'">
                @foreach ($ticks as $tick)
                    <span class="absolute top-1 font-mono text-[10px] text-neutral-400 dark:text-neutral-500 {{ $tick['last'] ? '-translate-x-full pr-1' : 'pl-1' }}"
                          style="left: {{ $tick['pct'] }}%">{{ $tick['label'] }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="flex items-stretch divide-x divide-neutral-200 dark:divide-neutral-800">
        {{-- Pinned tree pane: a plain flex sibling, entirely outside the
             chart's overflow-x-auto container, so it simply never scrolls
             horizontally — no sticky/z-index tricks required. --}}
        <div class="w-1/5 max-w-[250px] shrink-0 overflow-hidden whitespace-nowrap">
            @foreach ($rows as $row)
                <x-monitor::requests.timeline-row :entry="$row['entry']" :kind="$row['kind']" :total="$totalDuration" :root-label="$rootLabel" part="label"/>
            @endforeach

            @if ($orphanRows !== [])
                <div class="flex h-9 items-center border-t border-neutral-50 pr-3 dark:border-neutral-800/40">
                    <span class="h-9 w-4 shrink-0 border-l border-neutral-300 dark:border-neutral-700"></span>
                    <span class="pl-2 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Other</span>
                </div>
                @foreach ($orphanRows as $row)
                    <x-monitor::requests.timeline-row :entry="$row['entry']" :kind="$row['kind']" :total="$totalDuration" :root-label="$rootLabel" part="label"/>
                @endforeach
            @endif
        </div>

        {{-- Horizontally-scrolling chart pane. overflow-y-hidden is required,
             not decorative: overflow-x-auto alone makes the browser treat
             overflow-y as auto too (the CSS overflow-x/y coupling rule), and
             the invisible hover-tooltip <div>s inside each row (position:
             absolute; top-full) extend past this box's edges — without it
             this becomes a real (if invisible) vertical scroll container
             that traps the mouse wheel instead of letting it scroll the page. --}}
        <div class="relative flex-1 overflow-x-auto overflow-y-hidden bg-neutral-50/50 dark:bg-transparent"
             x-ref="scrollArea" @scroll="scrollLeft = $event.target.scrollLeft" @mousemove.window="onDrag($event)" @mouseup.window="stopDrag()">
            <div :style="'width: ' + (zoom * 100) + '%'" class="min-w-full select-none" :class="dragging ? 'cursor-grabbing' : 'cursor-grab'" @mousedown="startDrag($event)">
                {{-- Rows + full-height gridlines/crosshair overlay --}}
                <div class="relative" x-ref="rows" @mousemove="track($event)" @mouseleave="crossX = null">
                    {{-- Vertical gridlines aligned to the ruler ticks --}}
                    <div class="pointer-events-none absolute inset-0 z-0">
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
                        <x-monitor::requests.timeline-row :entry="$row['entry']" :kind="$row['kind']" :total="$totalDuration" :root-label="$rootLabel" part="bar"/>
                    @endforeach

                    {{-- Events that didn't fall inside any recorded phase --}}
                    @if ($orphanRows !== [])
                        <div class="h-9 border-t border-neutral-50 dark:border-neutral-800/40"></div>
                        @foreach ($orphanRows as $row)
                            <x-monitor::requests.timeline-row :entry="$row['entry']" :kind="$row['kind']" :total="$totalDuration" :root-label="$rootLabel" part="bar"/>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        {{-- Selected event detail panel: a right-hand side panel next to the
             chart, not a bottom drawer, so inspecting an event never pushes
             the timeline down the page. The inner wrapper is `sticky` (not
             the outer flex item) so it pins near the top of the viewport for
             as long as the row list beside it is tall enough to scroll
             through, instead of scrolling away with the rows the moment you
             pass its own — much shorter — content height. `top-32` clears
             the dashboard's own sticky page header (~120px) so the two don't
             overlap. --}}
        <div x-show="selectedId !== null" x-cloak x-transition class="w-80 shrink-0">
            <div class="sticky top-32 max-h-[calc(100vh-9rem)] divide-y divide-neutral-200 overflow-y-auto bg-neutral-50 dark:divide-neutral-800 dark:bg-neutral-900/50">
                <div class="flex items-start justify-between gap-2 p-4">
                    <div class="min-w-0">
                        <h3 class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400" x-text="selected()?.type"></h3>
                        <span class="mt-0.5 block font-mono text-xs text-neutral-400 dark:text-neutral-500" x-text="selectedTimestamp()"></span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <template x-if="selected()?.type === 'query'">
                            <a :href="selected()?.queryUrl" title="View Query"
                               class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                            </a>
                        </template>
                        <template x-if="selected()?.type === 'notification'">
                            <a :href="selected()?.notificationUrl" title="View Notification"
                               class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                            </a>
                        </template>
                        <template x-if="selected()?.type === 'mail'">
                            <a :href="selected()?.mailUrl" title="View Mail"
                               class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                            </a>
                        </template>
                        <button type="button" @click="selectedId = null" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOSE" :stroke="2" class="h-4 w-4"/>
                        </button>
                    </div>
                </div>

                {{-- SQL — syntax-highlighted via the highlight.js build already
                     loaded for stack traces (see components/layout.blade.php). --}}
                <template x-if="selected()?.type === 'query'">
                    <div class="p-4">
                        <div class="mb-1.5 flex items-center justify-between">
                            <span class="font-mono text-[10px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">SQL</span>
                            <button type="button" @click="copySql()" title="Copy" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" class="h-3.5 w-3.5"/>
                            </button>
                        </div>
                        <div class="max-h-64 overflow-auto">
                            <pre class="whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-neutral-800 dark:text-neutral-200"><code data-line-code data-lang="sql" x-html="sqlHighlighted()"></code></pre>
                        </div>
                    </div>
                </template>

                <template x-if="selected()?.type === 'query'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200" x-text="selected()?.duration + 'ms'"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Connection</dt>
                            <dd class="flex items-center gap-1.5 font-mono text-neutral-800 dark:text-neutral-200">
                                <span x-text="selected()?.metadata?.connection"></span>
                                <span class="rounded px-1 py-0.5 text-[10px] font-medium"
                                      :class="sqlIsWrite() ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'"
                                      x-text="sqlIsWrite() ? 'WRITE' : 'READ'"></span>
                            </dd>
                        </div>
                        <template x-if="selected()?.metadata?.location">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">File</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200" :title="selected()?.metadata?.location" x-text="selected()?.metadata?.location"></dd>
                            </div>
                        </template>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'cache'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Key</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200" :title="selected()?.metadata?.key" x-text="selected()?.metadata?.key"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Operation</dt>
                            <dd class="font-mono uppercase text-neutral-800 dark:text-neutral-200" x-text="selected()?.metadata?.subtype"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200" x-text="selected()?.duration + 'ms'"></dd>
                        </div>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'notification'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Notification</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200" :title="selected()?.metadata?.notification" x-text="selected()?.label"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Channel</dt>
                            <dd class="font-mono uppercase text-neutral-800 dark:text-neutral-200" x-text="selected()?.metadata?.channel"></dd>
                        </div>
                        <template x-if="selected()?.metadata?.notifiable">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Notifiable</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200" :title="selected()?.metadata?.notifiable" x-text="selected()?.metadata?.notifiable"></dd>
                            </div>
                        </template>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200" x-text="selected()?.duration + 'ms'"></dd>
                        </div>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'mail'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Subject</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200" :title="selected()?.metadata?.subject" x-text="selected()?.metadata?.subject"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">To</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200" :title="selected()?.metadata?.to" x-text="selected()?.metadata?.to"></dd>
                        </div>
                        <template x-if="selected()?.metadata?.notification">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Via</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200" :title="selected()?.metadata?.notification" x-text="selected()?.metadata?.notification"></dd>
                            </div>
                        </template>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200" x-text="selected()?.duration + 'ms'"></dd>
                        </div>
                    </dl>
                </template>
            </div>
        </div>
    </div>
</x-monitor::card>
