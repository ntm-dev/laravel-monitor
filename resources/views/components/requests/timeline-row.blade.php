{{-- TimelineRow: renders either the pinned tree-column label (part="label")
     or the horizontally-scrolling chart bar (part="bar") for one waterfall
     row -- request root, phase header, or event. timeline.blade.php renders
     each row twice, once into each pane, from the same $rows list: the tree
     pane is a plain flex sibling that never joins the chart's horizontal
     scroll, so it needs no
     sticky-positioning tricks and can't be pushed in front of the page's own
     header the way a shared-scroll-container hack previously could.
     Hover highlighting is kept in sync across both panes via the shared
     Alpine `hoveredId` state from timeline.blade.php; the blue "selected"
     highlight only applies to {@see $detailable} rows, since only those can
     actually be selected.

     Label rows draw their own indentation as literal vertical guide lines
     (one per ancestor level: phases hang one level off Request, events hang
     one level off their phase) instead of plain padding, so the tree reads
     like a file-explorer tree rather than a flat indented list.

     Only query/cache events open the inspector panel (see
     TimelineRow::DETAILABLE_TYPES) -- root, phases, and every other event
     type just show their hover tooltip and aren't clickable. --}}
@php
    $depth = match ($kind) { 'phase', 'attempt' => 1, 'event' => 2, default => 0 };
    $highlightClass = $detailable
        ? "selectedId === '{$entry->id}' ? 'bg-blue-50/60 dark:bg-blue-500/5' : (hoveredId === '{$entry->id}' ? 'bg-neutral-50 dark:bg-neutral-800/60' : '')"
        : "hoveredId === '{$entry->id}' ? 'bg-neutral-50 dark:bg-neutral-800/60' : ''";
    // Full text for the tree pane's hover tooltip — only the root row and
    // events actually have a truncated label worth expanding; empty skips
    // showTooltip() below (see timeline.blade.php).
    $tooltipText = match (true) {
        $kind === 'root' => $entry->label,
        $kind === 'event' => $tooltipDetail,
        default => '',
    };
    // A root row always stays visible (it's the track's own summary bar,
    // doubling as the affordance to expand/collapse it) — same for its own
    // 'attempt' row directly underneath (see Timeline::__construct()): that
    // pair is the at-a-glance summary for the whole track, so both stay
    // visible collapsed or not. Every other row in this track only renders
    // while its own track is expanded (see timeline.blade.php's
    // `expandedTracks` state). Expanding one track never collapses another —
    // each track's own expand state is independent.
    $visible = in_array($kind, ['root', 'attempt'], true) ? 'true' : "expandedTracks['{$trackId}']";
    $toggleClick = "toggleTrack('{$trackId}')";
@endphp
@if ($part === 'label')
    <div x-show="{{ $visible }}"
         @class(['relative flex h-9 min-w-0 items-center pr-3', 'cursor-pointer' => $detailable || ($kind === 'root' && $focusable)])
         :class="{{ $highlightClass }}"
         @mouseenter="hoveredId = '{{ $entry->id }}'; showTooltip($event, @js($tooltipText))"
         @mouseleave="hoveredId = null; hideTooltip()"
         @if ($detailable) @click="selectRow('{{ $entry->id }}')"
         @elseif ($kind === 'root' && $focusable) @click="{{ $toggleClick }}" @endif>
        @for ($i = 0; $i < $depth; $i++)
            <span class="h-9 w-4 shrink-0 border-l ml-2 border-neutral-300 dark:border-neutral-700"></span>
        @endfor
        <div class="flex min-w-0 translate-y-px items-center gap-1.5 {{ $depth > 0 ? 'pl-2' : 'pl-3' }}">
            @if ($kind === 'root')
                @if ($focusable)
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2"
                                      class="h-3 w-3 shrink-0 text-neutral-400 transition-transform dark:text-neutral-500"
                                      x-bind:class="expandedTracks['{{ $trackId }}'] ? '' : '-rotate-90'"/>
                @endif
                <span class="font-mono text-[11px] font-semibold text-neutral-800 dark:text-neutral-100">{{ $rootLabel }}</span>
                <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $entry->label }}</span>
            @elseif ($kind === 'attempt')
                <span class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Attempt #{{ $attempt }}</span>
            @elseif ($kind === 'phase')
                <span class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-600 dark:text-neutral-300">{{ $entry->label }}</span>
                @if ($entry->metadata['controller'] ?? null)
                    <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500" title="{{ $entry->metadata['controller'] }}">{{ $entry->metadata['controller'] }}</span>
                @endif
            @else
                @if ($duplicateColor)
                    <span class="relative flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-full border border-{{ $duplicateColor }}-500 pl-px text-[8px] font-bold leading-none text-{{ $duplicateColor }}-500 dark:border-{{ $duplicateColor }}-400 dark:text-{{ $duplicateColor }}-400"
                          :class="(heartbeatActive || heartbeatColor === '{{ $duplicateColor }}') ? 'monitor-heartbeat' : ''">D</span>
                @else
                    <span class="flex h-3.5 w-3.5 shrink-0 items-center justify-center">
                        <span class="relative h-1.5 w-1.5 rounded-full {{ $color }}"></span>
                    </span>
                @endif
                <span class="shrink-0 font-mono text-[11px] font-medium {{ $badgeTextColor }}">{{ $badge }}</span>
                <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $detail }}</span>
            @endif
        </div>
    </div>
@else
    {{-- data-duplicate-group marks the first-in-DOM-order match for the
         EventSummary "N duplicates" click handler's scrollIntoView() (see
         timeline.blade.php) — first in the timeline == first chronologically. --}}
    <div x-show="{{ $visible }}" class="relative flex h-9 items-center" :class="{{ $highlightClass }}" @if ($duplicateColor) data-duplicate-group @endif>
        <div @class(['relative flex h-full items-center', 'cursor-pointer' => $detailable || ($kind === 'root' && $focusable), 'scroll-mt-[169px]' => $kind === 'root'])
             style="margin-left: {{ $left }}%; width: {{ $width }}%; min-width: 3px" data-row-id="{{ $entry->id }}"
             @if ($kind === 'root') data-track-root="{{ $trackId }}" @endif
             @mouseenter="hoveredId = '{{ $entry->id }}'" @mouseleave="hoveredId = null"
             @if ($detailable) @click="selectRow('{{ $entry->id }}')"
             @elseif ($kind === 'root' && $focusable) @click="{{ $toggleClick }}" @endif>
            @if ($kind === 'root')
                <span class="absolute left-0 top-1/2 h-7 w-full -translate-y-1/2 rounded {{ $rootColor }}"></span>
                <div class="sticky left-0 z-10 flex h-6 translate-y-px items-center gap-1.5 whitespace-nowrap px-2">
                    @if ($status !== null)
                        <span class="inline-flex h-5 shrink-0 items-center rounded px-1 font-mono text-[11px] {{ $statusBadgeClass }}">{{ $status }}</span>
                    @endif
                    <span class="font-mono text-[11px] font-semibold text-neutral-700 dark:text-neutral-200">{{ $rootLabel }}</span>
                    <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}</span>
                    <span class="max-w-lg truncate font-mono text-[11px] text-neutral-500 dark:text-neutral-400">{{ $entry->label }}</span>
                </div>
            @elseif ($kind === 'attempt')
                {{-- Same bar position/width as the root row directly above it
                     (same underlying entry, see Timeline::__construct()) —
                     just recoloured to the attempt's own outcome status,
                     which the root deliberately leaves neutral. --}}
                <span class="absolute left-0 top-1/2 h-7 w-full -translate-y-1/2 rounded {{ $rootColor }}"></span>
                <div class="sticky left-0 z-10 flex h-6 translate-y-px items-center gap-1.5 whitespace-nowrap px-2">
                    <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">Attempt #{{ $attempt }}</span>
                    @if ($statusLabel !== null)
                        <span class="inline-flex h-5 shrink-0 items-center rounded px-1 font-mono text-[11px] {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
                    @endif
                    <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}</span>
                </div>
            @elseif ($kind === 'phase')
                <span class="absolute left-0 top-1/2 h-6 w-full -translate-y-1/2 rounded border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800"></span>
                <div class="sticky left-0 z-10 flex h-6 translate-y-px items-center gap-1.5 whitespace-nowrap px-1.5">
                    <span class="font-mono text-[11px] uppercase tracking-tight text-neutral-700 dark:text-neutral-200">{{ $entry->label }}</span>
                    @if ($durationLabel !== '')
                        <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}</span>
                    @endif
                    @if ($entry->metadata['controller'] ?? null)
                        <span class="max-w-sm truncate font-mono text-[11px] text-neutral-500 dark:text-neutral-400" title="{{ $entry->metadata['controller'] }}">{{ $entry->metadata['controller'] }}</span>
                    @endif
                </div>
            @else
                <span class="absolute left-0 top-1/2 h-6 w-full -translate-y-1/2 rounded {{ $barColor }}"
                      @if ($detailable) :class="selectedId === '{{ $entry->id }}' ? 'ring-1 ring-blue-500' : ''" @endif>
                    @if ($tooltipDetail !== '')
                        <div class="pointer-events-none invisible absolute bottom-full left-0 z-30 mb-1.5 max-w-md whitespace-pre-wrap break-words rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-neutral-100 opacity-0 shadow-lg transition-opacity duration-100"
                             :class="hoveredId === '{{ $entry->id }}' ? 'visible opacity-100' : ''">
                            {{ $tooltipDetail }}
                        </div>
                    @endif
                </span>
                <div class="sticky left-0 z-10 flex h-6 translate-y-px items-center gap-1.5 whitespace-nowrap px-1.5">
                    <span class="font-mono text-[11px] font-medium text-neutral-700 dark:text-neutral-200">{{ $badge }}</span>
                    @if ($durationLabel !== '')
                        <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}</span>
                    @endif
                    <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $detailShort }}</span>
                </div>
            @endif
        </div>
    </div>
@endif
