{{-- TimelineRow: renders either the pinned tree-column label (part="label")
     or the horizontally-scrolling chart bar (part="bar") for one waterfall
     row -- request root, phase header, or event. timeline.blade.php renders
     each row twice, once into each pane, from the same $rows list: the tree
     pane is a plain flex sibling that never joins the chart's horizontal
     scroll (Nightwatch's own two-pane layout), so it needs no
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
     type just show their hover tooltip and aren't clickable, matching
     Nightwatch. --}}
@php
    $depth = match ($kind) { 'phase' => 1, 'event' => 2, default => 0 };
    $highlightClass = $detailable
        ? "selectedId === '{$entry->id}' ? 'bg-blue-50/60 dark:bg-blue-500/5' : (hoveredId === '{$entry->id}' ? 'bg-neutral-50 dark:bg-neutral-800/60' : '')"
        : "hoveredId === '{$entry->id}' ? 'bg-neutral-50 dark:bg-neutral-800/60' : ''";
@endphp
@if ($part === 'label')
    <div @class(['relative flex h-9 min-w-0 items-center pr-3', 'cursor-pointer' => $detailable])
         :class="{{ $highlightClass }}"
         @mouseenter="hoveredId = '{{ $entry->id }}'" @mouseleave="hoveredId = null"
         @if ($detailable) @click="selectRow('{{ $entry->id }}')" @endif>
        @for ($i = 0; $i < $depth; $i++)
            <span class="h-9 w-4 shrink-0 border-l border-neutral-300 dark:border-neutral-700"></span>
        @endfor
        <div class="flex min-w-0 items-center gap-1.5 {{ $depth > 0 ? 'pl-2' : 'pl-3' }}">
            @if ($kind === 'root')
                <span class="font-mono text-[11px] font-semibold text-neutral-800 dark:text-neutral-100">REQUEST</span>
                <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $entry->label }}</span>
            @elseif ($kind === 'phase')
                <span class="font-mono text-[11px] uppercase tracking-tight text-neutral-600 dark:text-neutral-300">{{ $entry->label }}</span>
            @else
                <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $color }}"></span>
                <span class="shrink-0 font-mono text-[11px] font-medium text-neutral-700 dark:text-neutral-200">{{ $badge }}</span>
                <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $detail }}</span>

                {{-- Full SQL / cache key on hover — the label above is CSS-truncated,
                     this shows the untruncated $detail in a floating tooltip. --}}
                @if ($detail !== '')
                    <div class="pointer-events-none invisible absolute left-8 top-full z-30 mt-1 max-w-md whitespace-pre-wrap break-words rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-neutral-100 opacity-0 shadow-lg transition-opacity duration-100"
                         :class="hoveredId === '{{ $entry->id }}' ? 'visible opacity-100' : ''">
                        {{ $detail }}
                    </div>
                @endif
            @endif
        </div>
    </div>
@else
    <div @class(['relative flex h-9 items-center', 'cursor-pointer' => $detailable])
         :class="{{ $highlightClass }}"
         @mouseenter="hoveredId = '{{ $entry->id }}'" @mouseleave="hoveredId = null"
         @if ($detailable) @click="selectRow('{{ $entry->id }}')" @endif>
        <div class="relative flex h-full items-center" style="margin-left: {{ $left }}%; width: {{ $width }}%; min-width: 3px">
            @if ($kind === 'root')
                <span class="absolute left-0 top-1/2 h-6 w-full -translate-y-1/2 rounded {{ $rootColor }}"></span>
                <div class="sticky left-0 z-10 flex h-6 items-center gap-1.5 whitespace-nowrap px-2">
                    <span class="font-mono text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">{{ $durationLabel }}</span>
                    <span class="max-w-lg truncate font-mono text-[11px] text-neutral-500 dark:text-neutral-400">{{ $entry->label }}</span>
                </div>
            @elseif ($kind === 'phase')
                <span class="absolute left-0 top-1/2 h-6 w-full -translate-y-1/2 rounded border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800"></span>
                <div class="sticky left-0 z-10 flex h-6 items-baseline gap-1.5 whitespace-nowrap px-1.5">
                    <span class="font-mono text-[11px] uppercase tracking-tight text-neutral-700 dark:text-neutral-200">{{ $entry->label }}</span>
                    <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}</span>
                </div>
            @else
                <span class="relative h-6 w-full shrink-0 rounded {{ $barColor }}"
                      @if ($detailable) :class="selectedId === '{{ $entry->id }}' ? 'ring-1 ring-blue-500' : ''" @endif>
                    @if ($detail !== '')
                        <div class="pointer-events-none invisible absolute bottom-full left-0 z-30 mb-1.5 max-w-md whitespace-pre-wrap break-words rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-neutral-100 opacity-0 shadow-lg transition-opacity duration-100"
                             :class="hoveredId === '{{ $entry->id }}' ? 'visible opacity-100' : ''">
                            {{ $detail }}
                        </div>
                    @endif
                </span>
                <div class="sticky left-0 z-10 ml-1.5 flex shrink-0 items-baseline gap-1.5 whitespace-nowrap">
                    <span class="font-mono text-[11px] font-medium text-neutral-700 dark:text-neutral-200">{{ $badge }}</span>
                    <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}</span>
                    <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $detailShort }}</span>
                </div>
            @endif
        </div>
    </div>
@endif
