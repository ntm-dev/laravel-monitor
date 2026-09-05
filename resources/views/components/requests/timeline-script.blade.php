{{-- Alpine state/behavior for timeline.blade.php's x-data, split out purely
     to keep that file's markup readable — rendered via
     x-data="{!! view('monitor::components.requests.timeline-script', [...])->render() !!}"
     on the <x-monitor::card> root (NOT @include: a component tag's own
     attribute compiler only evaluates {{ }}/{!! !!}, leaving @directive(...)
     there as inert text), so it shares that element's Alpine scope exactly
     as if inlined. Needs its variables passed explicitly (unlike @include,
     this doesn't inherit the caller's scope automatically). Not registered/
     used as an <x-monitor::...> component: it renders a bare object
     literal, not an element. --}}
{
    {{-- Placeholder until init() measures the real viewport and swaps in
         defaultZoom()'s value. --}}
    zoom: 1,
    {{-- 1x = the whole timeline fits the viewport (pane width is a pure
         `zoom * 100%` of its container). --}}
    minZoom: 1,
    {{-- Zoom level at which the shortest real-duration bar reaches
         MIN_ITEM_PX (3px, see minWidthPx below) is `minWidthPx /
         viewportWidth`. maxZoom doubles that, so the old fixed "1x" level
         now sits at the slider's midpoint instead of pinned to one end.
         Recomputed live off viewportWidth, floored at minZoom so a
         timeline that already fits at 1x never gets a max below its min. --}}
    maxZoom() {
        if (!this.viewportWidth) return this.minZoom;

        return Math.max(this.minZoom, (2 * this.minWidthPx) / this.viewportWidth);
    },
    {{-- The zoom level on first load: always 1x (minZoom), regardless of
         where maxZoom() ends up — not its midpoint. --}}
    defaultZoom() {
        return this.minZoom;
    },
    scrollLeft: 0,
    crossX: null,
    crossWidth: 0,
    totalDuration: {{ $totalDuration }},
    {{-- Shortest real-duration bar's width in px at zoom=1 — see
         View\Components\Requests\Timeline::$minWidthPx. Only used as
         maxZoom()'s input now; no longer baked into the pane's own CSS
         width (that used to force a min-width floor, which is what made
         1x overflow the viewport instead of fitting it). --}}
    minWidthPx: {{ $minWidthPx }},
    {{-- Chart pane's own width in px (not its scrolled inner content) —
         kept as state so handleResize() has a value to hand refreshTicks(). --}}
    viewportWidth: 0,
    {{-- Which tracks show their phase/event rows — starts with just the
         default track (see MergesJobTimelines::defaultTrackId()); toggling
         any other track via toggleTrack() only touches its own entry. --}}
    expandedTracks: { '{{ $defaultTrack }}': true },
    {{-- Fallbacks only — measureHeaderOffsets() overwrites both before
         paint. pageHeaderOffset is the dashboard's own sticky page header's
         real height (varies per page, see measureHeaderOffsets()). --}}
    pageHeaderOffset: 120,
    {{-- pageHeaderOffset plus this timeline's own title/zoom/ruler row —
         where the selected-event detail panel's sticky position starts. --}}
    timelineHeaderOffset: 169,
    measureHeaderOffsets() {
        {{-- A page can hold several page headers with only one shown at a
             time (the Request Detail page renders one per info bundle and
             toggles them), so measure the one actually laid out rather than
             whichever comes first in the DOM — a hidden one measures 0 and
             would leave this timeline's own sticky bar sitting under it. --}}
        const pageHeader = Array.from(document.querySelectorAll('header.sticky.top-0'))
            .find((el) => el.getBoundingClientRect().height > 0);
        this.pageHeaderOffset = pageHeader ? Math.ceil(pageHeader.getBoundingClientRect().height) : 120;
        const ownHeader = this.$refs.stickyHeader;
        this.timelineHeaderOffset = this.pageHeaderOffset + (ownHeader ? Math.ceil(ownHeader.getBoundingClientRect().height) : 49);
    },
    measureViewport() {
        this.viewportWidth = this.$refs.scrollArea?.clientWidth || 0;
    },
    handleResize() {
        this.measureHeaderOffsets();
        this.measureViewport();
        {{-- maxZoom() moves with viewportWidth — a shrinking resize can
             leave zoom stale above the new ceiling. --}}
        this.zoom = Math.min(this.zoom, this.maxZoom());
        this.refreshTicks();
    },
    {{-- Ruler ticks + gridlines, populated by refreshTicks() (called once
         in init() so they're correct before first paint, then kept updated
         on zoom/resize but never on scroll — scrolling alone can fire many
         times a second, and re-deriving ticks on each is wasted work).
         Computed client-side rather than precomputed in PHP: the real tick
         set depends on viewport width and the current zoom, neither of
         which the server knows. --}}
    ticks: [],
    {{-- Bumped on every refreshTicks() call — growTicksInBackground()'s
         queued frames bail out once this no longer matches their own
         token, so a zoom fired mid-fill doesn't race a newer generation. --}}
    ticksToken: 0,
    {{-- Recomputes `ticks` across the page's entire 0-100% span (not just
         what's visible) so panning alone still finds marks anywhere.
         Spaced densely enough that any viewport-width-wide window contains
         at least 4 of them — denser zoom levels need proportionally more,
         which is why this only reruns on zoom/resize, not scroll.

         A page whose bars span several orders of magnitude of duration can
         demand tens of thousands of ticks — building them all into the DOM
         at once would freeze the page for seconds, so this builds only the
         handful nearest the current scroll position synchronously, then
         hands the rest to growTicksInBackground() to paint in over several
         frames, nearest-first.

         $refs.rowsInner is the same scrolled element the bars/gridlines
         render inside, so its offsetWidth is the exact px-per-ms scale the
         current zoom produced — measuring it directly avoids re-deriving
         that CSS width formula a second time here. --}}
    refreshTicks() {
        const totalWidthPx = this.$refs.rowsInner?.offsetWidth || 0;

        if (totalWidthPx <= 0 || this.totalDuration <= 0) {
            this.ticks = [];

            return;
        }

        const viewportWidth = this.viewportWidth || totalWidthPx;
        const count = Math.max(8, Math.ceil((4 * totalWidthPx) / viewportWidth));
        const stepMs = this.totalDuration / count;
        {{-- Kept as a float, not rounded to a whole ms: a dense enough
             count (zoom near maxZoom() onto a sub-millisecond bar) can put
             adjacent ticks well under 1ms apart, and rounding would
             collapse them onto the same value. formatTickMs() below
             already renders sub-ms ticks in μs.
             stepMs itself (the gap between adjacent ticks) drives how many
             decimals formatTickMs() shows, since position alone isn't a
             reliable guide — two ticks a fraction of a ms apart can still
             sit tens of seconds into the timeline. --}}
        const buildTick = (i) => {
            const ms = stepMs * i;

            return { ms, label: this.formatTickMs(ms, stepMs), pct: (ms / this.totalDuration) * 100, first: i === 0, last: i === count };
        };

        {{-- Tick indexes ordered by distance from the current scroll
             position — growTicksInBackground() consumes this front-to-back,
             so what's on screen finishes first regardless of full count.
             Built by walking outward from the center index in both
             directions (a "ring walk"), not by sorting every 0..count
             index by distance: count can reach the tens of thousands on a
             page whose bars span several orders of magnitude of duration
             (see this method's own docs above), and Array.from(...).sort()
             over that many elements — a comparator call per comparison,
             O(n log n) of them — measured at several ms on its own, real
             enough to read as the zoom slider itself lagging when this ran
             on every 'input' event. A ring walk visits the same n indices
             exactly once each, in the same order sorting would have
             produced (nearest-to-center first), in O(n) with no comparator
             calls at all. --}}
        const centerIndex = Math.max(0, Math.min(count, Math.round(((this.scrollLeft + viewportWidth / 2) / totalWidthPx) * count)));
        const order = [centerIndex];
        for (let lo = centerIndex - 1, hi = centerIndex + 1; lo >= 0 || hi <= count; lo--, hi++) {
            if (lo >= 0) order.push(lo);
            if (hi <= count) order.push(hi);
        }

        const token = ++this.ticksToken;
        const immediate = order.splice(0, Math.min(order.length, 40));

        this.ticks = this.dedupeAgainst([], immediate.map(buildTick));

        if (order.length > 0) {
            this.growTicksInBackground(order, buildTick, token);
        }
    },
    {{-- Paints the rest of a refreshTicks() queue into `ticks` a chunk at a
         time, one requestAnimationFrame apart, so a page needing thousands
         of ticks never blocks in one pass. --}}
    growTicksInBackground(remaining, buildTick, token) {
        const CHUNK_SIZE = 60;

        requestAnimationFrame(() => {
            {{-- A newer refreshTicks() call bumped ticksToken past what
                 this chain was handed — stop instead of racing it. --}}
            if (token !== this.ticksToken) {
                return;
            }

            const chunk = this.dedupeAgainst(this.ticks, remaining.splice(0, CHUNK_SIZE).map(buildTick));

            this.ticks = this.ticks.concat(chunk);

            if (remaining.length > 0) {
                this.growTicksInBackground(remaining, buildTick, token);
            }
        });
    },
    {{-- Drops any `candidates` tick whose label already appears in
         `existing` — only actually filters anything when stepMs itself
         rounds away to nothing. --}}
    dedupeAgainst(existing, candidates) {
        const seen = new Set(existing.map((tick) => tick.label));

        return candidates.filter((tick) => (seen.has(tick.label) ? false : (seen.add(tick.label), true)));
    },
    {{-- Mirrors Support\Format::duration()'s largest-whole-unit choice
         (h/m/s/ms/μs) but not its fixed 2-decimal precision: stepMs (the
         gap between adjacent ticks) drives how many decimals get shown,
         since a zoomed-in tick can need sub-ms resolution even at a large
         absolute position. --}}
    formatTickMs(ms, stepMs) {
        const trim = (fixed) => fixed.replace(/\.?0+$/, '');
        const decimalsFor = (stepInUnit) => Math.min(6, Math.max(2, Math.ceil(-Math.log10(Math.max(stepInUnit, 1e-9)))));

        for (const [unitMs, suffix] of [[3600000, 'h'], [60000, 'm'], [1000, 's']]) {
            if (ms >= unitMs) return trim((ms / unitMs).toFixed(decimalsFor(stepMs / unitMs))) + suffix;
        }

        if (ms > 0 && ms < 1) return trim((ms * 1000).toFixed(decimalsFor(stepMs * 1000))) + 'μs';

        return trim(ms.toFixed(decimalsFor(stepMs))) + 'ms';
    },
    {{-- The detail panel's own duration readout — same largest-whole-unit
         ladder and fixed 2-decimal precision as Support\Format::duration(),
         so a sub-1ms event reads "760μs" here too instead of the raw
         "0.76ms" the panel used to concatenate directly. --}}
    formatDuration(ms) {
        if (ms === null || ms === undefined) return '—';

        const trim = (fixed) => fixed.replace(/\.?0+$/, '');

        for (const [unitMs, suffix] of [[3600000, 'h'], [60000, 'm'], [1000, 's'], [1, 'ms']]) {
            if (ms >= unitMs) return trim((ms / unitMs).toFixed(2)) + suffix;
        }

        if (ms > 0) return trim((ms * 1000).toFixed(2)) + 'μs';

        return trim(ms.toFixed(2)) + 'ms';
    },
    init() {
        this.measureHeaderOffsets();
        this.measureViewport();
        {{-- No $nextTick needed: unlike refreshTicks() below, this doesn't
             read anything back off the DOM. --}}
        this.zoom = this.defaultZoom();
        {{-- $nextTick, not straight away: $refs.rowsInner's own :style (the
             zoom*100% stretch) hasn't applied to the DOM yet at this point
             in Alpine's init — measuring offsetWidth now would catch its
             pre-stretch size. --}}
        this.$nextTick(() => this.refreshTicks());
        if (!{{ $scrollTargetRowId !== null || $defaultTrack !== ($tracks[0]['id'] ?? null) ? 'true' : 'false' }}) return;
        this.$nextTick(() => {
            {{-- $scrollTargetRowId (see View\Components\Requests\Timeline)
                 names one specific attempt's own row, not just its track's
                 root -- landing here via that exact attempt's own link (e.g.
                 its 3rd retry, several rows below the track's root) should
                 scroll straight to it rather than merely the track's top.
                 Centered on both axes, same as scrollToType() below (fired
                 by the EventSummary cards) -- 'start' only moves the
                 vertical axis and can leave an off-track row sitting right
                 under the page's own sticky header, since (unlike a track's
                 own root row) an attempt row carries no scroll-mt-[169px]
                 offset for it (see TimelineRow.php's $rootColor/'root' kind
                 — that class is root-only). Not 'smooth' like scrollToType()
                 though: that one fires from a stable, already-settled page
                 on a user click, but this runs from init() while
                 measureViewport()/the zoom-driven pane-width reflow are
                 still settling -- a 'smooth' scroll's in-flight animation
                 got silently cut short once, landing near the start, when a
                 resize/layout shift happened to land mid-animation. --}}
            @if ($scrollTargetRowId !== null)
                this.$refs.rows.querySelector(`[data-row-id='{{ $scrollTargetRowId }}']`)?.scrollIntoView({ behavior: 'auto', inline: 'center', block: 'center' });
            @else
                this.$refs.rows.querySelector(`[data-track-root='{{ $defaultTrack }}']`)?.scrollIntoView({ behavior: 'auto', block: 'start' });
            @endif
        });
    },
    toggleTrack(id) {
        this.expandedTracks[id] = !this.expandedTracks[id];
        if (!this.expandedTracks[id]) return;
        {{-- Jump to the track just expanded — with several stacked on the
             page, it can land well below the fold. --}}
        this.$nextTick(() => {
            this.$refs.rows.querySelector(`[data-track-root='${id}']`)?.scrollIntoView({ behavior: 'auto', block: 'start' });
        });
    },
    selectedId: null,
    hoveredId: null,
    {{-- True for 5s whenever the EventSummary 'N duplicates' badge is
         clicked, so every dot sharing that query's colour pulses at once —
         the visual equivalent of highlighting an N+1. --}}
    heartbeatActive: false,
    heartbeatTimer: null,
    {{-- Same pulse, scoped to just the clicked query's own duplicate group
         (see selectRow() below) rather than every group on the page — keyed
         by duplicateGroup, not duplicateColor, since the 10-colour palette
         repeats. --}}
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
    {{-- Mirrors Support\Number::fileSize()'s B/KB/MB/... scaling — the
         queue detail panel renders client-side from the JSON entry map,
         not Blade. --}}
    formatBytes(value) {
        if (!value) return '';
        if (value < 1024) return value + ' B';
        const units = ['KB', 'MB', 'GB', 'TB'];
        let scaled = value;
        for (const unit of units) {
            scaled /= 1024;
            if (scaled < 1024) return scaled.toFixed(1) + ' ' + unit;
        }
        return scaled.toFixed(1) + ' TB';
    },
    mailClass() {
        const m = this.selected()?.metadata;
        return m?.mailable || m?.notification || '';
    },
    sqlHighlighted() {
        const sql = this.selected()?.metadata?.sql;
        if (!sql) return '';
        {{-- 'mysql' dialect, not the generic 'sql' one: the generic dialect
             doesn't know backtick-quoted identifiers and pads them with
             stray spaces. Laravel backtick-quotes on every grammar it ships
             except Postgres/SQL Server, so this is right even for a
             non-MySQL connection's recorded SQL. --}}
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
    {{-- Fixed-position, not the row's own absolutely-positioned child: the
         pinned tree pane clips overflow to hold its column width steady,
         which would otherwise clip this tooltip when the truncated text it
         shows extends past the pane's edge. --}}
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
        return ratio * this.totalDuration;
    },
    {{-- ms represented by one pixel of mouse movement at the current zoom —
         the same role stepMs plays for formatTickMs() above, so the
         crosshair readout stays legible instead of frozen at a rounded
         whole ms once 1ms already spans many px. --}}
    crossStepMs() {
        return this.crossWidth > 0 ? this.totalDuration / this.crossWidth : 0;
    },
    {{-- Anchor whatever ms sits at the viewport's *center*, not its left
         edge — anchoring the edge lets the point the user was actually
         looking at drift away as the pane grows/shrinks.
         Deliberately computed from this.zoom/this.totalDuration/viewportWidth
         alone, never by reading el.scrollWidth: the pane's width is exactly
         `zoom * viewportWidth` (the CSS is a literal `zoom * 100%` of this
         same container), so that reactive state already IS the width,
         without a DOM round-trip.
         zoomCenterMs is captured once per *burst* of calls, not on every one:
         a real slider drag fires setZoom() many times in quick succession,
         each with its own $nextTick, and el.scrollLeft is only actually
         updated once the *previous* call's $nextTick has run — a call that
         lands before that (zoomPending still true) would otherwise read a
         stale el.scrollLeft left over from before this burst started,
         anchoring to the wrong point. Reusing the same zoomCenterMs for the
         whole burst instead means every call in it targets the one point
         the gesture actually started from, and only the burst's *last*
         queued $nextTick — the one that runs once el.scrollLeft has finally
         settled — needs to fire for real; earlier ones become redundant,
         harmless repeats of the same write. --}}
    {{-- The <input type="range"> below binds to *this*, not to `zoom`
         directly, and step 0-1 rather than minZoom-maxZoom(): zoom spans
         several orders of magnitude (1x to sometimes 1000x+, whenever a
         page mixes a microsecond-scale bar with a multi-second one — see
         View\Components\Requests\Timeline::$minWidthPx), and a slider whose
         track maps that whole range linearly gives every pixel of physical
         mouse movement a wildly different meaning depending where the thumb
         already is: near the low end a pixel is a fraction of a unit, near
         the high end it can be dozens or hundreds of units. The same small,
         imprecise nudge that barely changes anything at 2x can swing the
         pane's rendered width by tens of thousands of px at 1000x, which
         reads as the view lurching/losing its place even though the
         center-anchoring math itself (setZoom() below) is exact — the jump
         it's asked to center around is just enormous. Mapping the track to
         zoom *logarithmically* instead means equal physical movement is
         equal *percentage* change everywhere on the track, so the pane
         grows/shrinks by a comparable, controllable amount per pixel
         regardless of current zoom. --}}
    zoomSliderValue() {
        const min = this.minZoom, max = this.maxZoom();
        return max > min ? Math.log(this.zoom / min) / Math.log(max / min) : 0;
    },
    setZoomFromSlider(position) {
        const min = this.minZoom, max = this.maxZoom();
        this.setZoom(max > min ? min * Math.pow(max / min, position) : min);
    },
    zoomCenterMs: 0,
    zoomPending: false,
    zoomRafId: null,
    zoomTarget: null,
    {{-- Actually *applying* a zoom value is expensive at the zoom levels
         this page can reach: the pane's own width can run into the
         millions of px, and refreshTicks() below can need many thousands
         of tick marks to keep its own "at least 4 per viewport" guarantee
         at that scale. A real slider drag fires the 'input' event far
         faster than the page can usefully re-layout and rebuild all of
         that — every extra call in between two actual paints is pure
         wasted work, and doing it anyway is what reads as the zoom
         "lagging"/stuttering while dragging. Collapsing every call within
         one animation frame into a single requestAnimationFrame callback
         caps the real work to once per frame — exactly as often as the
         browser can show the result — regardless of how many raw events
         arrive in between; only zoomTarget (a plain number, essentially
         free to overwrite) tracks what the *next* frame should apply. --}}
    setZoom(next) {
        this.zoomTarget = Math.min(this.maxZoom(), Math.max(this.minZoom, next));
        if (this.zoomRafId !== null) return;
        const el = this.$refs.scrollArea;
        const viewportWidth = el.clientWidth;
        if (!this.zoomPending) {
            const oldContentWidth = this.zoom * viewportWidth;
            this.zoomCenterMs = oldContentWidth > 0 ? ((el.scrollLeft + viewportWidth / 2) / oldContentWidth) * this.totalDuration : 0;
        }
        this.zoomPending = true;
        this.zoomRafId = requestAnimationFrame(() => {
            this.zoomRafId = null;
            this.zoom = this.zoomTarget;
            this.$nextTick(() => {
                const newContentWidth = this.zoom * viewportWidth;
                el.scrollLeft = (this.zoomCenterMs / this.totalDuration) * newContentWidth - viewportWidth / 2;
                this.scrollLeft = el.scrollLeft;
                this.zoomPending = false;
                this.debounceRefreshTicks();
            });
        });
    },
    {{-- refreshTicks() itself is cheap now (~0.1ms even at extreme zoom —
         see its own docs), but it still rewrites `ticks`, and Alpine has to
         re-render however many tick/gridline elements that x-for produces
         on every single write. A slider drag can still call this dozens of
         times before settling even with setZoom()'s own per-frame cap
         above, and every one of those renders a tick set that's obsolete
         the instant the next frame's zoom lands — wasted paint work the
         user never actually sees, which is its own source of jank
         independent of how fast refreshTicks() itself runs. Debouncing
         means only the *last* zoom level in a drag — the one still showing
         once motion actually stops — ever pays for a real tick render;
         everything in between keeps the pane's own width live (setZoom()
         above still runs every frame) with whatever tick set was already
         on screen, which reads as smooth panning of the existing ruler
         rather than a fresh ruler flashing in on every frame. --}}
    refreshTicksDebounceTimer: null,
    debounceRefreshTicks() {
        clearTimeout(this.refreshTicksDebounceTimer);
        this.refreshTicksDebounceTimer = setTimeout(() => this.refreshTicks(), 120);
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
    {{-- Only ever *opens*/switches the detail panel to `id` — never closes
         it, even when `id` is already selected. The X button
         (timeline-detail-panel.blade.php) is the only thing that sets
         selectedId back to null; a row click re-clicking the same event is
         a no-op rather than a close, since a stray click on the timeline
         while reading the panel shouldn't dismiss it out from under you. --}}
    selectRow(id) {
        if (this.dragMoved) return;
        this.selectedId = id;
        {{-- Selecting a duplicate query pulses every dot sharing its group,
             so an N+1 is obvious without hunting for the EventSummary
             badge. --}}
        clearTimeout(this.heartbeatGroupTimer);
        const group = this.data[id]?.duplicateGroup ?? null;
        this.heartbeatGroup = group;
        if (group) {
            this.heartbeatGroupTimer = setTimeout(() => this.heartbeatGroup = null, 6000);
        }
        this.scrollToBar(id);
    },
    {{-- The only thing that closes the detail panel (see selectRow()'s own
         docs above) — closing it widens the chart pane back out by 320px
         the same way opening one narrows it. Re-centers on the event that
         was open, same as selectRow() does on the event it opens, but
         *without* animating (see scrollToBar()'s own `smooth` param): this
         is a resize compensation, not a "go look at this" navigation — done
         right, the bar should just silently stay under the eye, not visibly
         glide there, which reads as the timeline scrolling on its own for
         no reason the user asked for. --}}
    closeDetail() {
        const id = this.selectedId;
        this.selectedId = null;
        if (id !== null) this.scrollToBar(id, false);
    },
    {{-- Centers a bar's own *left edge* (not its midpoint — see below) in
         the chart pane's horizontal scroll. Shared by selectRow() (smooth:
         true, the default — a deliberate jump to whatever was just picked)
         and closeDetail() (smooth: false — a same-frame layout compensation
         that should look like nothing moved, not a scroll) and phase rows
         (TimelineRow::$scrollable), which have no inspector panel at all.
         $nextTick: selecting a row that opens the detail panel for the
         first time shrinks the chart pane by 320px, which hasn't hit the
         DOM yet at this point — waiting a tick lets Alpine flush that
         layout change first so the scroll math uses the narrower, final
         width; harmless when nothing resizes (switching between two
         already-selected rows, or the phase-row case).
         Not bar.scrollIntoView({ inline: 'center' }): that centers the
         bar's own midpoint, which for a short event next to a long
         phase/root bar can land nowhere near where it actually starts.
         Centering the left edge instead keeps that start point steady
         regardless of bar width. --}}
    scrollToBar(id, smooth = true) {
        this.$nextTick(() => {
            const bar = Array.from(this.$refs.rows.querySelectorAll('[data-row-id]')).find(el => el.dataset.rowId === id);
            if (!bar) return;
            const scrollArea = this.$refs.scrollArea;
            const barStart = bar.getBoundingClientRect().left - scrollArea.getBoundingClientRect().left + scrollArea.scrollLeft;
            const target = Math.max(0, Math.min(barStart - scrollArea.clientWidth / 2, scrollArea.scrollWidth - scrollArea.clientWidth));
            if (smooth) {
                scrollArea.scrollTo({ left: target, behavior: 'smooth' });
            } else {
                scrollArea.scrollLeft = target;
            }
        });
    },
    {{-- Fired by the EventSummary cards above the timeline (every type but
         'queries', which has its own duplicates-heartbeat scroll instead)
         — jumps to the first row of that type in DOM order, i.e. the
         nearest one chronologically since rows render in timeline order. --}}
    scrollToType(type) {
        const row = Array.from(this.$refs.rows.querySelectorAll('[data-row-id]')).find(el => this.data[el.dataset.rowId]?.type === type);
        if (!row) return;
        {{-- Only query/cache/mail/notification/lazy_loading/exception/http
             rows are ever detailable (see TimelineRow::DETAILABLE_TYPES),
             baked server-side into this element's own 'cursor-pointer'
             class — checked here to avoid a second JS-side copy of that
             type list. --}}
        if (row.classList.contains('cursor-pointer')) {
            {{-- selectRow()'s own nextTick only centers the horizontal
                 axis — it assumes the row is already visible vertically,
                 true when clicked directly but not here (a summary card
                 can be anywhere on the page). Center vertically first. --}}
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            this.selectRow(row.dataset.rowId);
        } else {
            {{-- Nothing to select for queue rows (no inspector panel) —
                 just center the bar in both axes directly. --}}
            row.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'center' });
        }
    },
}
