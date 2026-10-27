{{-- Request lifecycle waterfall: pinned tree pane + horizontally scrolling chart pane, as flex siblings.
     Alpine state comes from timeline-script.blade.php rendered into x-data (a component tag's attributes
     don't compile directives); the sub-views share that Alpine scope. `isolate` keeps sticky labels under
     the page header; clip-path (not overflow) rounds corners so the sticky detail panel still works. --}}
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
        <x-monitor::requests.timeline-detail-panel :has-controller="$hasController" />
    </div>

    {{-- Shared tree-pane tooltip, fixed to the viewport (see showTooltip()/hideTooltip()). --}}
    <div x-show="tooltip.text !== ''" x-cloak
        class="pointer-events-none fixed z-50 max-w-md whitespace-pre-wrap break-words rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-neutral-100 shadow-lg"
        :style="'top: ' + tooltip.top + 'px; left: ' + tooltip.left + 'px'" x-text="tooltip.text"></div>

    {{-- Duplicate-SQL dot "heartbeat" rings, using currentColor so one rule fits every group colour.
         Toggled by heartbeatActive/heartbeatGroup. --}}
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
