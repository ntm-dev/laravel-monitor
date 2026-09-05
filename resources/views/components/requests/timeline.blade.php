{{-- Timeline: waterfall view of the request lifecycle. Two-pane layout — a
     pinned tree pane (timeline-tree.blade.php) and an independently
     horizontally-scrolling chart pane (timeline-chart.blade.php), as
     separate flex siblings so the tree never joins the chart's own scroll.
     The header (timeline-header.blade.php) mirrors the same width split:
     title + zoom slider over the tree pane, ruler ticks over the chart
     pane, kept in sync with panning/zooming via a manual transform since
     the ruler lives outside the scrolling pane. Every row/tick position is
     precomputed by View\Components\Requests\Timeline against one fixed
     scale for the whole page — expanding/collapsing a track only toggles
     visibility, it never moves the scale.

     Alpine state/behavior lives in timeline-script.blade.php, rendered
     directly into `x-data` below via `{!! view(...)->render() !!}` — NOT
     `@include(...)`: <x-monitor::card> is a Blade *component* tag, and the
     component-tag compiler only evaluates `{{ }}`/`{!! !!}` inside an
     attribute value, leaving any `@directive(...)` there as inert literal
     text (verified via Blade::compileString() — `@include` came out
     untouched in the compiled attribute array, which broke Alpine's own
     x-data parsing at runtime). The four included sub-views below aren't
     standalone reusable components — they're this one view split across
     files for readability, relying on Alpine's DOM-scoped reactivity (not
     Blade variable scope) to still see zoom/selectedId/etc.

     `isolate`: the chart pane's sticky bar labels are z-10, and without
     their own stacking context they'd tie with (and paint over) the
     dashboard's own sticky page header, also z-10. `clip-path`, not
     `overflow-hidden`, rounds the corners: the detail panel further down
     is `sticky` and a *descendant* of this card — `overflow` on an
     ancestor would silently turn that `sticky` into a no-op by becoming
     the tracked scroll container instead of the window. --}}
<x-monitor::card id="timeline" class="isolate p-0 [clip-path:inset(0_round_0.5rem)]"
    @monitor-duplicates-heartbeat.window="
         heartbeatActive = true;
         clearTimeout(heartbeatTimer);
         heartbeatTimer = setTimeout(() => heartbeatActive = false, 6000);
         $el.querySelector('[data-duplicate-group]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
     "
    @monitor-scroll-to-timeline-event.window="scrollToType($event.detail.type)"
    x-data="{!! view('monitor::components.requests.timeline-script', [
        'totalDuration' => $totalDuration,
        'minWidthPx' => $minWidthPx,
        'defaultTrack' => $defaultTrack,
        'scrollTargetRowId' => $scrollTargetRowId,
        'tracks' => $tracks,
        'entriesJson' => $entriesJson,
    ])->render() !!}"
    @resize.window="handleResize()">
    <x-monitor::requests.timeline-header />

    <div class="flex items-stretch divide-x divide-neutral-200 dark:divide-neutral-800">
        <x-monitor::requests.timeline-tree :rows="$rows" />
        <x-monitor::requests.timeline-chart :rows="$rows" />
        <x-monitor::requests.timeline-detail-panel />
    </div>

    {{-- Shared tree-pane tooltip, positioned in the viewport (not the row)
         -- see showTooltip()/hideTooltip() in timeline-script.blade.php. --}}
    <div x-show="tooltip.text !== ''" x-cloak
        class="pointer-events-none fixed z-50 max-w-md whitespace-pre-wrap break-words rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-neutral-100 shadow-lg"
        :style="'top: ' + tooltip.top + 'px; left: ' + tooltip.left + 'px'" x-text="tooltip.text"></div>

    {{-- Duplicate-SQL dot "heartbeat": two staggered rings expanding from
         the dot and fading out, via currentColor so one rule covers every
         group's colour (see TimelineRow::$duplicateColor). Toggled by
         heartbeatActive/heartbeatGroup in timeline-script.blade.php. --}}
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
