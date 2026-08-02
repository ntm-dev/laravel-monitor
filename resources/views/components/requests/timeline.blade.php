{{-- Timeline: a waterfall view of the request lifecycle. True
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
     the page header (outside this context) always wins instead. Every row's
     position (and the ruler) is precomputed by View\Components\Requests\Timeline
     against one fixed scale for the whole page (the `$defaultTrack` track's
     own duration) — expanding/collapsing a track here is purely a visibility
     toggle (`expandedTracks` below), independent per track; it never moves
     the scale or auto-collapses any other track.

     Corners are clipped with `clip-path`, not `overflow-hidden`, because the
     selected-event detail panel further down is `position: sticky` and is a
     *descendant* of this card: `overflow: hidden/auto/scroll` on an ancestor
     makes that ancestor the sticky-tracking scroll container per the CSS
     Overflow spec, and since this card itself never scrolls (the window
     does), that silently turns the panel's `sticky` into a no-op — it just
     rides away with the rest of the card instead of pinning to the
     viewport. `clip-path` still rounds the same corners but, unlike
     `overflow`, doesn't establish a scroll container, so the sticky panel
     keeps tracking the real (window) scroll. --}}
<x-monitor::card id="timeline" class="isolate p-0 [clip-path:inset(0_round_0.5rem)]"
    @monitor-duplicates-heartbeat.window="
         heartbeatActive = true;
         clearTimeout(heartbeatTimer);
         heartbeatTimer = setTimeout(() => heartbeatActive = false, 6000);
         $el.querySelector('[data-duplicate-group]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
     "
    @monitor-scroll-to-timeline-event.window="scrollToType($event.detail.type)" x-data="{
        zoom: 1,
        minZoom: 1,
        maxZoom: 8,
        scrollLeft: 0,
        crossX: null,
        crossWidth: 0,
        totalDuration: {{ $totalDuration }},
        // Which tracks currently show their own phase/event rows — starts
        // with just the page's default track (see
        // MergesJobTimelines::defaultTrackId()); toggling any other track on
        // via toggleTrack() below only ever adds to/removes from this set,
        // it never touches another track's own entry.
        expandedTracks: { '{{ $defaultTrack }}': true },
        // Landing here already scoped to a specific job track (see
        // MergesJobTimelines::defaultTrackId()) — it starts expanded above,
        // but with other tracks stacked ahead of it on the page it can
        // still render below the fold, so jump to it immediately, same as
        // toggleTrack() does for a manual expand. No-op when the default
        // track is already the page's first (nothing to scroll to).
        init() {
            if (!{{ $defaultTrack !== ($tracks[0]['id'] ?? null) ? 'true' : 'false' }}) return;
            this.$nextTick(() => {
                this.$refs.rows.querySelector(`[data-track-root='{{ $defaultTrack }}']`)?.scrollIntoView({ behavior: 'auto', block: 'start' });
            });
        },
        toggleTrack(id) {
            this.expandedTracks[id] = !this.expandedTracks[id];
            if (!this.expandedTracks[id]) return;
            // Jump straight to the track that was just expanded — with
            // several tracks stacked on the page, the one you just opened
            // can easily be scrolled off well below the fold.
            this.$nextTick(() => {
                this.$refs.rows.querySelector(`[data-track-root='${id}']`)?.scrollIntoView({ behavior: 'auto', block: 'start' });
            });
        },
        selectedId: null,
        hoveredId: null,
        // Toggled true for 5s (see the window listener above) whenever the
        // EventSummary 'N duplicates' badge is clicked, so every dot
        // sharing that query's colour (see TimelineRow::$duplicateColor)
        // pulses at once — the visual equivalent of highlighting an N+1.
        heartbeatActive: false,
        heartbeatTimer: null,
        // Same pulse, scoped to just the clicked query's own duplicate
        // group (see selectRow() below) rather than every group on the
        // page — keyed by TimelineRow::$duplicateGroup, not $duplicateColor:
        // the 10-colour palette repeats, so two unrelated groups can share
        // a colour and would otherwise pulse together.
        heartbeatGroup: null,
        heartbeatGroupTimer: null,
        tooltip: { text: '', top: 0, left: 0 },
        sqlCopied: false,
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
        exceptionLocation() {
            const file = this.selected()?.metadata?.file;
            const line = this.selected()?.metadata?.line;
            return file ? (line ? file + ':' + line : file) : '';
        },
        mailRecipients() {
            const m = this.selected()?.metadata;
            if (!m) return '';
            const parts = [];
            if (m.to_count) parts.push(m.to_count + ' TO');
            if (m.cc_count) parts.push(m.cc_count + ' CC');
            if (m.bcc_count) parts.push(m.bcc_count + ' BCC');
            return parts.join(' / ');
        },
        mailAttachments() {
            const names = this.selected()?.metadata?.attachment_names || [];
            const count = this.selected()?.metadata?.attachments || 0;
            return names.length ? count + ' (' + names.join(', ') + ')' : String(count);
        },
        mailClass() {
            const m = this.selected()?.metadata;
            return m?.mailable || m?.notification || '';
        },
        sqlHighlighted() {
            const sql = this.selected()?.metadata?.sql;
            if (!sql) return '';
            {{-- 'mysql' dialect, not the generic 'sql' one, because the
                  generic dialect doesn't know backtick-quoted identifiers
                  and pads them with stray spaces (`` `users` `` → `` ` users ` ``);
                  Laravel's query builder backtick-quotes on every grammar it
                  ships except Postgres/SQL Server, so this is the right
                  default even for a non-MySQL connection's recorded SQL. --}}
            const formatted = window.sqlFormatter ? window.sqlFormatter.format(sql, { language: 'mysql' }) : sql;
            return window.hljs ? window.hljs.highlight(formatted, { language: 'sql', ignoreIllegals: true }).value : formatted;
        },
        copySql() {
            const sql = this.selected()?.metadata?.sql;
            if (!sql) return;
            navigator.clipboard.writeText(sql);
            this.sqlCopied = true;
            setTimeout(() => this.sqlCopied = false, 1500);
        },
        // Fixed-position, not the row's own absolutely-positioned child: the
        // pinned tree pane clips overflow to hold its column width steady
        // (see the label pane's `overflow-hidden` below), which would
        // otherwise clip this tooltip too whenever the truncated text it's
        // showing extends past the pane's edge.
        showTooltip(event, text) {
            if (!text) { return; }
            const rect = event.currentTarget.getBoundingClientRect();
            this.tooltip = { text, top: rect.bottom + 4, left: rect.left };
        },
        hideTooltip() {
            this.tooltip = { text: '', top: 0, left: 0 };
        },
        track(event) {
            const rect = this.$refs.rows.getBoundingClientRect();
            this.crossX = event.clientX - rect.left;
            this.crossWidth = rect.width;
        },
        crossMs() {
            if (this.crossX === null || !this.crossWidth) return 0;
            const ratio = Math.min(1, Math.max(0, this.crossX / this.crossWidth));
            return Math.round(ratio * this.totalDuration);
        },
        setZoom(next) {
            const el = this.$refs.scrollArea;
            const oldWidth = el.scrollWidth;
            const ratio = oldWidth > 0 ? el.scrollLeft / oldWidth : 0;
            this.zoom = Math.min(this.maxZoom, Math.max(this.minZoom, next));
            this.$nextTick(() => { el.scrollLeft = ratio * el.scrollWidth;
                this.scrollLeft = el.scrollLeft; });
        },
        startDrag(event) {
            if (event.button !== 0) return;
            this.dragging = true;
            this.dragMoved = false;
            this.dragStartX = event.clientX;
            this.dragScrollStart = this.$refs.scrollArea.scrollLeft;
        },
        onDrag(event) {
            if (!this.dragging) return;
            const dx = event.clientX - this.dragStartX;
            if (Math.abs(dx) > 3) this.dragMoved = true;
            this.$refs.scrollArea.scrollLeft = this.dragScrollStart - dx;
        },
        stopDrag() { this.dragging = false },
        selectRow(id) {
            if (this.dragMoved) return;
            this.selectedId = (this.selectedId === id ? null : id);
            // Selecting a duplicate query pulses every dot sharing its own
            // group (see TimelineRow::$duplicateGroup), so an N+1 is
            // obvious without hunting for the EventSummary badge.
            clearTimeout(this.heartbeatGroupTimer);
            const group = this.selectedId !== null ? this.data[this.selectedId]?.duplicateGroup : null;
            this.heartbeatGroup = group ?? null;
            if (group) {
                this.heartbeatGroupTimer = setTimeout(() => this.heartbeatGroup = null, 6000);
            }
            if (this.selectedId === null) return;
            // The tree pane's row is always vertically in view (it's what was
            // just clicked) but the chart pane scrolls independently on its
            // own horizontal axis — when zoomed in, the matching bar can sit
            // outside the current scroll window entirely.
            // $nextTick matters here: opening the detail panel shrinks the
            // chart pane's width by 320px (it's a flex sibling), but that
            // hasn't been applied to the DOM yet at the point selectedId is
            // set above — scrollIntoView run synchronously would measure the
            // pane's old, wider bounds. Waiting a tick lets Alpine flush the
            // panel's layout change first, so the scroll math uses the
            // narrower, final width.
            // inline: 'center' (not 'nearest') because 'nearest' only scrolls
            // the minimum distance needed, which parks the bar flush against
            // whichever edge it entered from — and the right edge of this
            // pane is the detail panel's own left border, so a bar arriving
            // from the right ends up sitting right on top of that seam,
            // reading as still covered. Centering it always leaves clear
            // space on both sides regardless of which edge it was outside.
            this.$nextTick(() => {
                const bar = Array.from(this.$refs.rows.querySelectorAll('[data-row-id]')).find(el => el.dataset.rowId === this.selectedId);
                bar?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            });
        },
        // Fired by the EventSummary cards above the timeline (every type but
        // 'queries', which has its own duplicates-heartbeat scroll instead)
        // — jumps to the first row of that type in DOM order, i.e. the
        // nearest one chronologically since rows render in timeline order.
        scrollToType(type) {
            const row = Array.from(this.$refs.rows.querySelectorAll('[data-row-id]')).find(el => this.data[el.dataset.rowId]?.type === type);
            if (!row) return;
            // Only query/cache/mail/notification/lazy_loading/exception/http
            // rows are ever detailable (see TimelineRow::DETAILABLE_TYPES) —
            // that's baked server-side into this same element's
            // 'cursor-pointer' class, so checking for it here avoids keeping
            // a second, JS-side copy of that type list that could drift out
            // of sync.
            if (row.classList.contains('cursor-pointer')) {
                // selectRow()'s own nextTick only centers the *horizontal*
                // (chart-pane) axis — it assumes the row is already visible
                // vertically, true when it was just clicked directly, but
                // not here, coming from a summary card that can be anywhere
                // on the page. Center it vertically first, then hand off.
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                this.selectRow(row.dataset.rowId);
            } else {
                // Nothing to select for queue rows (no inspector panel for
                // them) — just center the bar in both axes directly.
                row.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'center' });
            }
        },
    }">
    {{-- Sticky at top-[120px] for the same reason the detail panel below is:
         flush against the bottom of the dashboard's own sticky page header
         (see components/header.blade.php's `sticky top-0`, measured at
         120px). z-20 (not the z-10 used elsewhere in this card) so it stays
         above the rows pane's own sticky bar labels as they scroll
         underneath it, and it needs its own opaque background — sticky
         content doesn't get one for free — matching x-monitor::card's
         `bg-white dark:bg-neutral-900` so scrolled-past rows don't show
         through. --}}
    <div
        class="sticky top-[120px] z-20 flex items-stretch divide-x divide-neutral-200 border-b border-neutral-100 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
        <div class="flex w-1/5 max-w-[250px] shrink-0 items-center justify-between gap-3 px-4 py-3">
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">Timeline</h2>
            <div class="flex items-center gap-1.5">
                <input type="range" :min="minZoom" :max="maxZoom" step="0.1" :value="zoom"
                    @input="setZoom($event.target.valueAsNumber)"
                    class="h-1.5 w-14 cursor-pointer appearance-none rounded-full bg-neutral-200 accent-neutral-700 dark:bg-neutral-700 dark:accent-neutral-300" />
                <span class="w-8 text-right font-mono text-[10px] text-neutral-500 dark:text-neutral-400"
                    x-text="zoom.toFixed(1) + 'x'"></span>
            </div>
        </div>
        <div class="relative flex-1 overflow-hidden">
            <div class="relative h-full"
                :style="'width: ' + (zoom * 100) + '%; transform: translateX(-' + scrollLeft + 'px)'">
                {{-- First/last ticks stay edge-anchored (centering them would
                     push half the label off the pane); every other tick is
                     centered on its mark so it lines up with the crosshair,
                     which sits exactly at the mark itself. --}}
                @foreach ($ticks as $tick)
                    <span
                        class="absolute top-1 font-mono text-[10px] text-neutral-400 dark:text-neutral-500 {{ match (true) {
                            $tick['first'] => 'pl-1',
                            $tick['last'] => '-translate-x-full pr-1',
                            default => '-translate-x-1/2',
                        } }}"
                        style="left: {{ $tick['pct'] }}%">{{ $tick['label'] }}</span>
                @endforeach

                {{-- Hover ms readout, tracking the same crossX the rows pane's
                     crosshair uses — this container shares the identical
                     zoom-width + translateX(-scrollLeft) transform as the
                     rows pane, so a given crossX lines up with the same time
                     position in both places. Pinned to the bottom of the
                     ruler (flush with the border separating it from the rows
                     pane below) and coloured to match the blue crosshair
                     line, so it reads as a flag sitting directly on top of
                     that line rather than a disconnected floating label. --}}
                <div x-show="crossX !== null" x-cloak
                    class="pointer-events-none absolute bottom-0 z-10 -translate-x-1/2 whitespace-nowrap rounded-t bg-blue-500 px-1.5 py-0.5 font-mono text-[10px] text-white shadow dark:bg-blue-600"
                    :style="'left: ' + crossX + 'px'" x-text="crossMs() + 'ms'"></div>
            </div>
        </div>

        {{-- Mirrors the detail panel's width/visibility below (the w-80
             x-show="selectedId !== null" panel further down): the panel is a
             flex sibling of the chart pane in the content row, so opening it
             shrinks that row's flex-1 chart pane by 320px. Without a matching
             spacer here, this ruler row's own flex-1 pane wouldn't shrink to
             match, and the two rows' panes would end up different widths —
             which is exactly what made the whole timeline appear to jump
             left the instant a row was selected. --}}
        <div x-show="selectedId !== null" x-cloak class="w-80 shrink-0"></div>
    </div>

    <div class="flex items-stretch divide-x divide-neutral-200 dark:divide-neutral-800">
        {{-- Pinned tree pane: a plain flex sibling, entirely outside the
             chart's overflow-x-auto container, so it simply never scrolls
             horizontally — no sticky/z-index tricks required. --}}
        <div class="w-1/5 max-w-[250px] shrink-0 overflow-hidden whitespace-nowrap">
            @foreach ($rows as $row)
                @if ($row['kind'] === 'divider')
                    <div x-show="expandedTracks['{{ $row['track'] }}']"
                        class="flex h-9 items-center border-t border-neutral-50 pr-3 dark:border-neutral-800/40">
                        <span class="h-9 w-4 shrink-0 border-l border-neutral-300 dark:border-neutral-700"></span>
                        <span
                            class="pl-2 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Other</span>
                    </div>
                @else
                    <x-monitor::requests.timeline-row :entry="$row['entry']" :left="$row['left']" :width="$row['width']"
                        :kind="$row['kind']" :track-id="$row['track']" :root-label="$row['rootLabel']" :focusable="$row['focusable']" :attempt="$row['attempt']"
                        :job-status="$row['jobStatus']" :attempts-duration="$row['attemptsDuration']" :job-url="$row['jobUrl']" part="label" />
                @endif
            @endforeach
        </div>

        {{-- Horizontally-scrolling chart pane. overflow-y-hidden is required,
             not decorative: overflow-x-auto alone makes the browser treat
             overflow-y as auto too (the CSS overflow-x/y coupling rule), and
             the invisible hover-tooltip <div>s inside each row (position:
             absolute; top-full) extend past this box's edges — without it
             this becomes a real (if invisible) vertical scroll container
             that traps the mouse wheel instead of letting it scroll the page. --}}
        <div class="relative flex-1 overflow-x-auto overflow-y-hidden bg-neutral-50/50 dark:bg-transparent"
            x-ref="scrollArea" @scroll="scrollLeft = $event.target.scrollLeft" @mousemove.window="onDrag($event)"
            @mouseup.window="stopDrag()">
            <div :style="'width: ' + (zoom * 100) + '%'" class="min-w-full select-none"
                :class="dragging ? 'cursor-grabbing' : 'cursor-grab'" @mousedown="startDrag($event)">
                {{-- Rows + full-height gridlines/crosshair overlay --}}
                <div class="relative" x-ref="rows" @mousemove="track($event)" @mouseleave="crossX = null">
                    {{-- Vertical gridlines aligned to the ruler ticks --}}
                    <div class="pointer-events-none absolute inset-0 z-0">
                        @foreach ($ticks as $tick)
                            @unless ($tick['first'])
                                <div class="absolute inset-y-0 border-l border-neutral-100 dark:border-neutral-800/70"
                                    style="left: {{ $tick['pct'] }}%"></div>
                            @endunless
                        @endforeach
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

        {{-- Selected event detail panel: a right-hand side panel next to the
             chart, not a bottom drawer, so inspecting an event never pushes
             the timeline down the page. The inner wrapper is `sticky` (not
             the outer flex item) so it pins near the top of the viewport for
             as long as the row list beside it is tall enough to scroll
             through, instead of scrolling away with the rows the moment you
             pass its own — much shorter — content height. `sticky` alone is
             a no-op without an explicit offset, so `top-[169px]` sits the
             panel flush against the bottom of the timeline's own sticky
             title/zoom/ruler header above (`top-[120px] ... z-20` a bit up
             from here) — 120px for the dashboard's own sticky page header
             (back link + title row + subtitle row, all three pages sharing
             this Timeline component use the same header structure/padding)
             plus that header row's own rendered height (49px). Using the
             same `top-[120px]` as that header would make the two sticky
             elements land on the exact same viewport band, and whichever
             paints later (this panel, being later in the DOM) would cover
             the header instead of sitting below it. Matches the `169px`
             already baked into `max-h-[calc(100vh-169px)]` below, so the
             panel's sticky position plus its own max-height exactly reaches
             the bottom of the viewport. --}}
        {{-- No x-transition here on purpose: with it, Alpine silently failed
             to show this specific panel on the very first selection after
             page load (stayed at width 0 until toggled a second time) —
             verified by removing just x-transition, everything else equal.
             Plain x-show still switches display instantly and correctly. --}}
        <div x-show="selectedId !== null" class="w-80 shrink-0">
            <div
                class="sticky top-[169px] max-h-[calc(100vh-169px)] divide-y divide-neutral-200 overflow-y-auto dark:divide-neutral-800">
                <div class="flex items-start justify-between gap-2 p-4">
                    <div class="min-w-0">
                        <h3 class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400"
                            x-text="selected()?.badge"></h3>
                        <span class="mt-0.5 block font-mono text-xs text-neutral-400 dark:text-neutral-500"
                            x-text="selectedTimestamp()"></span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <template x-if="selected()?.type === 'query'">
                            <a :href="selected()?.queryUrl" title="View Query"
                                class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                            </a>
                        </template>
                        <template x-if="selected()?.type === 'notification'">
                            <a :href="selected()?.notificationUrl" title="View Notification"
                                class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                            </a>
                        </template>
                        <template x-if="selected()?.type === 'mail'">
                            <a :href="selected()?.mailUrl" title="View Mail"
                                class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                            </a>
                        </template>
                        <template x-if="selected()?.type === 'exception'">
                            <a :href="selected()?.exceptionUrl" title="View Exception"
                                class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                            </a>
                        </template>
                        <template x-if="selected()?.type === 'http'">
                            <a :href="selected()?.outgoingUrl" title="View Outgoing Request"
                                class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                            </a>
                        </template>
                        <button type="button" @click="selectedId = null"
                            class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOSE" :stroke="2" class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                {{-- SQL — syntax-highlighted via the highlight.js build already
                     loaded for stack traces (see components/layout.blade.php). --}}
                <template x-if="selected()?.type === 'query'">
                    <div class="p-4">
                        <div class="mb-1.5 flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">SQL</span>
                            <button type="button" @click="copySql()" title="Copy"
                                class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" class="h-3.5 w-3.5" x-show="! sqlCopied" />
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2"
                                    class="h-3.5 w-3.5 text-emerald-500" x-show="sqlCopied" x-cloak
                                    x-transition:enter="transition-[clip-path] ease-out duration-1000"
                                    x-transition:enter-start="[clip-path:inset(0_100%_0_0)]"
                                    x-transition:enter-end="[clip-path:inset(0_0_0_0)]" />
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
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.duration + 'ms'"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duplicates</dt>
                            <dd class="font-mono"
                                :class="selected()?.duplicateCount > 1 ? 'font-medium text-amber-600 dark:text-amber-400' :
                                    'text-neutral-800 dark:text-neutral-200'"
                                x-text="selected()?.duplicateCount"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Connection</dt>
                            <dd class="flex items-center gap-1.5 font-mono text-neutral-800 dark:text-neutral-200">
                                <span x-text="selected()?.metadata?.connection"></span>
                                {{-- The actual PDO connection role Laravel routed this query to
                                     (read/write/direct — see Recorders\Queries), not guessed from
                                     the SQL verb: a read replica can run a write-shaped statement
                                     under some setups, and a SELECT can just as easily run against
                                     the write connection (e.g. right after a write, under a sticky
                                     read/write split). Omitted entirely when the running Laravel
                                     version doesn't report it (< 12.45) or it's ambiguous. --}}
                                <template x-if="selected()?.metadata?.connection_type">
                                    <span class="rounded px-1 py-0.5 text-[10px] font-medium uppercase"
                                        :class="({
                                            write: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                                            read: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
                                            direct: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
                                        })[selected()?.metadata?.connection_type]"
                                        x-text="selected()?.metadata?.connection_type"></span>
                                </template>
                            </dd>
                        </div>
                        <template x-if="selected()?.metadata?.location">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">File</dt>
                                {{-- direction:rtl + text-align:left truncates the *front* of the
                                     string (the path) while keeping the tail (file:line) visible. --}}
                                <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    style="direction: rtl; text-align: left;" :title="selected()?.metadata?.location"
                                    x-text="selected()?.metadata?.location"></dd>
                            </div>
                        </template>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'cache'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Key</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                :title="selected()?.metadata?.key" x-text="selected()?.metadata?.key"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Operation</dt>
                            <dd class="font-mono uppercase text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.metadata?.subtype"></dd>
                        </div>
                        <template x-if="selected()?.metadata?.store">
                            <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                                <dt class="text-neutral-500 dark:text-neutral-400">Store</dt>
                                <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                    x-text="selected()?.metadata?.store"></dd>
                            </div>
                        </template>
                        <template x-if="selected()?.metadata?.ttl">
                            <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                                <dt class="text-neutral-500 dark:text-neutral-400">TTL</dt>
                                <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                    x-text="selected()?.metadata?.ttl + 's'"></dd>
                            </div>
                        </template>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.duration + 'ms'"></dd>
                        </div>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'notification'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Notification</dt>
                            {{-- direction:rtl + text-align:left truncates the *front* of the
                                 FQCN, keeping the class name itself (the tail) visible. --}}
                            <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                                style="direction: rtl; text-align: left;" :title="selected()?.metadata?.notification"
                                x-text="selected()?.label"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Channel</dt>
                            <dd class="font-mono uppercase text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.metadata?.channel"></dd>
                        </div>
                        <template x-if="selected()?.metadata?.notifiable">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Notifiable</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    :title="selected()?.metadata?.notifiable"
                                    x-text="selected()?.metadata?.notifiable"></dd>
                            </div>
                        </template>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.duration + 'ms'"></dd>
                        </div>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'mail'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Subject</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                :title="selected()?.metadata?.subject" x-text="selected()?.metadata?.subject"></dd>
                        </div>
                        <template x-if="mailRecipients()">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Recipients</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    x-text="mailRecipients()"></dd>
                            </div>
                        </template>
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">To</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                :title="selected()?.metadata?.to" x-text="selected()?.metadata?.to"></dd>
                        </div>
                        <template x-if="selected()?.metadata?.cc">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Cc</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    :title="selected()?.metadata?.cc" x-text="selected()?.metadata?.cc"></dd>
                            </div>
                        </template>
                        <template x-if="selected()?.metadata?.bcc">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Bcc</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    :title="selected()?.metadata?.bcc" x-text="selected()?.metadata?.bcc"></dd>
                            </div>
                        </template>
                        <template x-if="selected()?.metadata?.notification">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Via</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    :title="selected()?.metadata?.notification"
                                    x-text="selected()?.metadata?.notification"></dd>
                            </div>
                        </template>
                        <template x-if="selected()?.metadata?.attachments">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Attachments</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    :title="mailAttachments()" x-text="mailAttachments()"></dd>
                            </div>
                        </template>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.duration + 'ms'"></dd>
                        </div>
                        <template x-if="mailClass()">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Class</dt>
                                {{-- direction:rtl + text-align:left truncates the *front* of the
                                     FQCN, keeping the class name itself (the tail) visible. --}}
                                <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    style="direction: rtl; text-align: left;" :title="mailClass()"
                                    x-text="mailClass()"></dd>
                            </div>
                        </template>
                        <template x-if="selected()?.metadata?.mailer">
                            <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                                <dt class="text-neutral-500 dark:text-neutral-400">Mailer</dt>
                                <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                    x-text="selected()?.metadata?.mailer"></dd>
                            </div>
                        </template>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'lazy_loading'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Model</dt>
                            {{-- direction:rtl + text-align:left truncates the *front* of the
                                 FQCN, keeping the class name itself (the tail) visible. --}}
                            <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                                style="direction: rtl; text-align: left;" :title="selected()?.metadata?.model"
                                x-text="selected()?.metadata?.model"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Relation</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.metadata?.relation"></dd>
                        </div>
                        <template x-if="selected()?.metadata?.id">
                            <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Record ID</dt>
                                <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    x-text="selected()?.metadata?.id"></dd>
                            </div>
                        </template>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'http'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Method</dt>
                            <dd class="font-mono uppercase text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.metadata?.method"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Status</dt>
                            <dd class="font-mono font-medium"
                                :class="selected()?.metadata?.status == null ? 'text-neutral-400 dark:text-neutral-500' :
                                    selected()?.metadata?.status >= 500 ? 'text-rose-600 dark:text-rose-400' :
                                    selected()?.metadata?.status >= 400 ? 'text-amber-600 dark:text-amber-400' :
                                    'text-emerald-600 dark:text-emerald-400'"
                                x-text="selected()?.metadata?.status ?? 'Failed'"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">URL</dt>
                            <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                :title="selected()?.metadata?.url" x-text="selected()?.metadata?.url"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.duration !== null ? selected()?.duration + 'ms' : '—'"></dd>
                        </div>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'queue'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Job</dt>
                            {{-- direction:rtl + text-align:left truncates the *front* of the
                                 FQCN, keeping the class name itself (the tail) visible. --}}
                            <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                                style="direction: rtl; text-align: left;" :title="selected()?.metadata?.key"
                                x-text="selected()?.metadata?.key"></dd>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Status</dt>
                            <dd class="font-mono font-medium uppercase"
                                :class="({ processed: 'text-emerald-600 dark:text-emerald-400',
                                    failed: 'text-rose-600 dark:text-rose-400',
                                    released: 'text-amber-600 dark:text-amber-400',
                                    queued: 'text-neutral-500 dark:text-neutral-400' })[selected()?.metadata
                                ?.subtype] ?? 'text-neutral-800 dark:text-neutral-200'"
                                x-text="selected()?.metadata?.subtype ?? 'queued'"></dd>
                        </div>
                        <template x-if="selected()?.metadata?.queue">
                            <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                                <dt class="text-neutral-500 dark:text-neutral-400">Queue</dt>
                                <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                    x-text="selected()?.metadata?.queue"></dd>
                            </div>
                        </template>
                        <template x-if="selected()?.metadata?.connection">
                            <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                                <dt class="text-neutral-500 dark:text-neutral-400">Connection</dt>
                                <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                    x-text="selected()?.metadata?.connection"></dd>
                            </div>
                        </template>
                        <template x-if="selected()?.metadata?.attempt">
                            <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                                <dt class="text-neutral-500 dark:text-neutral-400">Attempt</dt>
                                <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                    x-text="'#' + selected()?.metadata?.attempt"></dd>
                            </div>
                        </template>
                        <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                            <dt class="text-neutral-500 dark:text-neutral-400">Duration</dt>
                            <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                                x-text="selected()?.duration !== null ? selected()?.duration + 'ms' : '—'"></dd>
                        </div>
                    </dl>
                </template>

                <template x-if="selected()?.type === 'exception'">
                    <div class="p-4">
                        <span
                            class="font-mono text-[10px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">Message</span>
                        <p class="mt-1.5 max-h-40 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-neutral-800 dark:text-neutral-200"
                            x-text="selected()?.metadata?.message"></p>
                    </div>
                </template>
                <template x-if="selected()?.type === 'exception'">
                    <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Class</dt>
                            {{-- direction:rtl + text-align:left truncates the *front* of the
                                 FQCN, keeping the class name itself (the tail) visible. --}}
                            <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                                style="direction: rtl; text-align: left;" :title="selected()?.metadata?.class"
                                x-text="selected()?.metadata?.class"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">File</dt>
                            {{-- direction:rtl + text-align:left is the standard trick for
                                 truncating the *front* of a string while text-overflow
                                 ellipsis keeps the tail (filename:line) visible. --}}
                            <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                                style="direction: rtl; text-align: left;" :title="exceptionLocation()"
                                x-text="exceptionLocation()"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                            <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">Handled</dt>
                            <dd class="font-mono font-medium"
                                :class="selected()?.metadata?.handled ? 'text-emerald-600 dark:text-emerald-400' :
                                    'text-rose-600 dark:text-rose-400'"
                                x-text="selected()?.metadata?.handled ? 'True' : 'False'"></dd>
                        </div>
                    </dl>
                </template>
            </div>
        </div>
    </div>

    {{-- Shared tree-pane tooltip, positioned in the viewport (not the row) --
         see showTooltip()/hideTooltip() above for why this can't just be an
         absolutely-positioned child of each row like the chart pane's own
         bar tooltips are. --}}
    <div x-show="tooltip.text !== ''" x-cloak
        class="pointer-events-none fixed z-50 max-w-md whitespace-pre-wrap break-words rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-neutral-100 shadow-lg"
        :style="'top: ' + tooltip.top + 'px; left: ' + tooltip.left + 'px'" x-text="tooltip.text"></div>

    {{-- Duplicate-SQL dot "heartbeat": two staggered rings expanding from
         the dot and fading out, coloured via currentColor so one rule works
         for every duplicate group's colour (see TimelineRow::$duplicateColor
         and the `text-{color}-500` class applied alongside .monitor-heartbeat
         in timeline-row.blade.php). Toggled on/off by heartbeatActive above. --}}
    <style>
        @keyframes monitor-heartbeat-ring {
            from {
                transform: scale(1);
                opacity: 0.7;
            }

            to {
                transform: scale(2.5);
                opacity: 0;
            }
        }

        .monitor-heartbeat::before,
        .monitor-heartbeat::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 9999px;
            background-color: currentColor;
            animation: monitor-heartbeat-ring 2s ease-out infinite;
        }

        .monitor-heartbeat::after {
            animation-delay: 0.5s;
        }
    </style>
</x-monitor::card>
