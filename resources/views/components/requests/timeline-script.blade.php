{{-- Alpine object literal for timeline.blade.php's x-data, rendered there with view()->render()
     since a component tag's attributes don't compile directives. Variables must be passed explicitly.
     Comments here must stay Blade comments placed between properties. --}}
{
    {{-- Placeholder until init() applies defaultZoom(). --}}
    zoom: 1,
    {{-- 1x = the whole timeline fits the viewport (pane width is zoom * 100%). --}}
    minZoom: 1,
    {{-- Twice the zoom at which the shortest bar reaches MIN_ITEM_PX, floored at minZoom. --}}
    maxZoom() {
        if (!this.viewportWidth) return this.minZoom;

        return Math.max(this.minZoom, (2 * this.minWidthPx) / this.viewportWidth);
    },
    defaultZoom() {
        return this.minZoom;
    },
    scrollLeft: 0,
    crossX: null,
    crossWidth: 0,
    totalDuration: {{ $totalDuration }},
    {{-- Pane width at which the shortest bar is MIN_ITEM_PX wide (Timeline::$minWidthPx); maxZoom()'s input. --}}
    minWidthPx: {{ $minWidthPx }},
    {{-- Chart pane's visible width in px, not its scrolled content. --}}
    viewportWidth: 0,
    {{-- Tracks showing their phase/event rows; starts with just the default track. --}}
    expandedTracks: { '{{ $defaultTrack }}': true },
    {{-- Fallbacks; measureHeaderOffsets() overwrites both. --}}
    pageHeaderOffset: 120,
    {{-- pageHeaderOffset plus the timeline header; where the detail panel sticks. --}}
    timelineHeaderOffset: 169,
    measureHeaderOffsets() {
        {{-- Pick the visible page header; Request Detail renders several and hides all but one. --}}
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
        {{-- maxZoom() depends on viewportWidth, so clamp after a resize. --}}
        this.zoom = Math.min(this.zoom, this.maxZoom());
        this.refreshTicks();
    },
    {{-- Ruler ticks + gridlines from refreshTicks(); refreshed on init, zoom and resize, not scroll. --}}
    ticks: [],
    {{-- Bumped per refreshTicks(); stale growTicksInBackground() chains stop when it changes. --}}
    ticksToken: 0,
    {{-- Ticks over the whole 0-100% span, at least 4 per viewport width. The ones nearest the
         scroll position are built now, the rest by growTicksInBackground(). Scale is read from
         $refs.rowsInner's offsetWidth. --}}
    refreshTicks() {
        const totalWidthPx = this.$refs.rowsInner?.offsetWidth || 0;

        if (totalWidthPx <= 0 || this.totalDuration <= 0) {
            this.ticks = [];

            return;
        }

        const viewportWidth = this.viewportWidth || totalWidthPx;
        const count = Math.max(8, Math.ceil((4 * totalWidthPx) / viewportWidth));
        const stepMs = this.totalDuration / count;
        {{-- ms stays unrounded (ticks can be under 1ms apart); stepMs sets formatTickMs() precision. --}}
        const buildTick = (i) => {
            const ms = stepMs * i;

            return { ms, label: this.formatTickMs(ms, stepMs), pct: (ms / this.totalDuration) * 100, first: i === 0, last: i === count };
        };

        {{-- Tick indexes nearest the viewport center first, via an O(n) outward walk (a sort was too slow). --}}
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
    {{-- Appends the remaining ticks one chunk per animation frame. --}}
    growTicksInBackground(remaining, buildTick, token) {
        const CHUNK_SIZE = 60;

        requestAnimationFrame(() => {
            {{-- Superseded by a newer refreshTicks(). --}}
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
    {{-- Drops candidates whose label already exists (only when stepMs rounds away). --}}
    dedupeAgainst(existing, candidates) {
        const seen = new Set(existing.map((tick) => tick.label));

        return candidates.filter((tick) => (seen.has(tick.label) ? false : (seen.add(tick.label), true)));
    },
    {{-- Format::duration()'s unit ladder, with decimals driven by stepMs instead of fixed at 2. --}}
    formatTickMs(ms, stepMs) {
        const trim = (fixed) => fixed.replace(/\.?0+$/, '');
        const decimalsFor = (stepInUnit) => Math.min(6, Math.max(2, Math.ceil(-Math.log10(Math.max(stepInUnit, 1e-9)))));

        for (const [unitMs, suffix] of [[3600000, 'h'], [60000, 'm'], [1000, 's']]) {
            if (ms >= unitMs) return trim((ms / unitMs).toFixed(decimalsFor(stepMs / unitMs))) + suffix;
        }

        if (ms > 0 && ms < 1) return trim((ms * 1000).toFixed(decimalsFor(stepMs * 1000))) + 'μs';

        return trim(ms.toFixed(decimalsFor(stepMs))) + 'ms';
    },
    {{-- Detail panel duration, matching Support\Format::duration(). --}}
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
        this.zoom = this.defaultZoom();
        {{-- After a tick, once rowsInner's zoom width has applied. --}}
        this.$nextTick(() => this.refreshTicks());
        if (!{{ $scrollTargetRowId !== null || $defaultTrack !== ($tracks[0]['id'] ?? null) ? 'true' : 'false' }}) return;
        this.$nextTick(() => {
            {{-- Scroll to the requested attempt row (centered: it lacks the root's scroll margin),
                 else the default track's root. Not smooth: layout is still settling here. --}}
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
        {{-- Scroll to the track just expanded. --}}
        this.$nextTick(() => {
            this.$refs.rows.querySelector(`[data-track-root='${id}']`)?.scrollIntoView({ behavior: 'auto', block: 'start' });
        });
    },
    selectedId: null,
    hoveredId: null,
    {{-- True for 6s after the EventSummary duplicates badge is clicked; pulses every duplicate dot. --}}
    heartbeatActive: false,
    heartbeatTimer: null,
    {{-- Pulses only the selected query's group (set by selectRow()); keyed by duplicateGroup, as colours repeat. --}}
    heartbeatGroup: null,
    heartbeatGroupTimer: null,
    tooltip: { text: '', top: 0, left: 0 },
    sqlCopied: false,
    traceCopied: false,
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
    {{-- Prefixes an LRM so a leading slash stays in place inside an rtl-truncated element. --}}
    truncatedPath(value) {
        return value ? '\u200E' + value : '';
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
    {{-- Client-side counterpart of Support\Number::fileSize(). --}}
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
        {{-- 'mysql' dialect: the generic one pads backtick-quoted identifiers with spaces. --}}
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
    {{-- Trace lines for display; vendor frames are dropped unless toggled on. --}}
    showVendor: false,
    visibleTrace() {
        const trace = this.selected()?.metadata?.trace ?? '';
        return trace.split('\n')
            {{-- A line with no "file(line):" part is a compact vendor frame. --}}
            .filter(line => this.showVendor || (/^#\d+ .+\(\d+\): /.test(line) && !/^#\d+ (?:.*[\\/])?vendor[\\/]/.test(line)))
            .map((line, index) => line
                .replace(/^#\d+ /, '#' + index + ' ')
                .replace(/^(#\d+) (.+)\((\d+)\): (.+)$/, (m, n, file, ln, fn) => n + ' ' + (/->|::/.test(fn) ? fn : file + ':' + ln)))
            .join('\n');
    },
    copyTrace() {
        const trace = this.visibleTrace();
        if (!trace) return;
        navigator.clipboard.writeText(trace);
        this.traceCopied = true;
        setTimeout(() => this.traceCopied = false, 1500);
    },
    {{-- Fixed-position, since the tree pane clips overflow. --}}
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
    {{-- ms per pixel; the crosshair readout's stepMs for formatTickMs(). --}}
    crossStepMs() {
        return this.crossWidth > 0 ? this.totalDuration / this.crossWidth : 0;
    },
    {{-- Slider position 0-1 mapped logarithmically to minZoom..maxZoom(), since zoom spans
         orders of magnitude. --}}
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
    {{-- Applies at most one zoom per animation frame (latest zoomTarget wins), keeping the ms at
         the viewport center fixed. zoomCenterMs is captured once per burst, while scrollLeft is stale. --}}
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
    {{-- Debounced so a slider drag re-renders ticks only once it settles. --}}
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
    {{-- Opens or switches the detail panel; never closes it (only closeDetail() does). --}}
    selectRow(id) {
        if (this.dragMoved) return;
        this.selectedId = id;
        {{-- Pulse every dot in the selected query's duplicate group. --}}
        clearTimeout(this.heartbeatGroupTimer);
        const group = this.data[id]?.duplicateGroup ?? null;
        this.heartbeatGroup = group;
        if (group) {
            this.heartbeatGroupTimer = setTimeout(() => this.heartbeatGroup = null, 6000);
        }
        this.scrollToBar(id);
    },
    {{-- Closes the panel and re-centers the previously selected bar without animating. --}}
    closeDetail() {
        const id = this.selectedId;
        this.selectedId = null;
        if (id !== null) this.scrollToBar(id, false);
    },
    {{-- Horizontally centers a bar's left edge (not its midpoint). Waits a tick so an
         opening/closing panel's width change has applied. --}}
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
    {{-- From the EventSummary cards (not queries): jumps to the first row of that type. --}}
    scrollToType(type) {
        const row = Array.from(this.$refs.rows.querySelectorAll('[data-row-id]')).find(el => this.data[el.dataset.rowId]?.type === type);
        if (!row) return;
        {{-- cursor-pointer marks rows the server made clickable (TimelineRow::$detailable). --}}
        if (row.classList.contains('cursor-pointer')) {
            {{-- selectRow() only scrolls horizontally, so center vertically first. --}}
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            this.selectRow(row.dataset.rowId);
        } else {
            {{-- Not selectable: just center the bar on both axes. --}}
            row.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'center' });
        }
    },
}
