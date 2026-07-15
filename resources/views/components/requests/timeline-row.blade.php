{{-- TimelineRow: one waterfall row — request root, phase header or event.
     All geometry and display strings come precomputed from
     View\Components\Requests\TimelineRow. Must render inside the Alpine
     scope set up by timeline.blade.php (selectedId/data/selectRow/dragMoved). --}}
<div class="group grid h-7 grid-cols-[16rem_1fr] items-stretch"
     :class="selectedId === '{{ $entry->id }}' ? 'bg-blue-50/60 dark:bg-blue-500/5' : ''"
     @click="selectRow('{{ $entry->id }}')">

    {{-- Pinned tree column. group-hover:z-40 lifts this row's whole sticky
         stacking context above the sibling rows below it — without it, a
         tooltip popping out of this column would render *behind* the next
         row's own z-20 sticky background, since sibling stacking contexts
         at equal z-index stack in DOM order (later wins). --}}
    <div class="sticky left-0 z-20 flex min-w-0 cursor-pointer items-center gap-1.5 border-r border-neutral-200 bg-white pr-3 group-hover:z-40 group-hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:group-hover:bg-neutral-800/60
        {{ $kind === 'event' ? 'pl-8' : ($kind === 'phase' ? 'pl-5' : 'pl-3') }}">
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
                 this shows the untruncated $detail in a floating tooltip anchored
                 to the pinned column so it stays visible regardless of horizontal scroll. --}}
            @if ($detail !== '')
                <div class="pointer-events-none invisible absolute left-8 top-full z-30 mt-1 max-w-md whitespace-pre-wrap break-words rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-neutral-100 opacity-0 shadow-lg transition-opacity duration-100 group-hover:visible group-hover:opacity-100">
                    {{ $detail }}
                </div>
            @endif
        @endif
    </div>

    {{-- Chart cell --}}
    <div class="relative cursor-pointer group-hover:bg-neutral-50/60 dark:group-hover:bg-neutral-800/30">
        @if ($kind === 'root')
            <div class="absolute top-1/2 flex h-5 -translate-y-1/2 items-center gap-1.5 overflow-hidden rounded px-2 {{ $rootColor }}"
                 style="left: {{ $left }}%; width: {{ $width }}%; min-width: 3px">
                <span class="whitespace-nowrap font-mono text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">{{ $durationLabel }}</span>
                <span class="truncate whitespace-nowrap font-mono text-[11px] text-neutral-500 dark:text-neutral-400">{{ $entry->label }}</span>
            </div>
        @elseif ($kind === 'phase')
            <div class="absolute top-1/2 h-5 -translate-y-1/2 rounded border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800"
                 style="left: {{ $left }}%; width: {{ $width }}%; min-width: 3px"
                 :class="selectedId === '{{ $entry->id }}' ? 'ring-1 ring-blue-500' : ''"></div>
            <div class="absolute top-1/2 flex -translate-y-1/2 items-baseline gap-1.5 whitespace-nowrap px-1.5"
                 style="left: {{ $left }}%">
                <span class="font-mono text-[11px] uppercase tracking-tight text-neutral-700 dark:text-neutral-200">{{ $entry->label }}</span>
                <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}</span>
            </div>
        @else
            <div class="group/bar absolute top-1/2 h-3.5 -translate-y-1/2 rounded-sm {{ $color }}"
                 style="left: {{ $left }}%; width: {{ $width }}%; min-width: 3px"
                 :class="selectedId === '{{ $entry->id }}' ? 'ring-1 ring-blue-500 ring-offset-1 dark:ring-offset-neutral-900' : ''">
                @if ($detail !== '')
                    <div class="pointer-events-none invisible absolute bottom-full left-0 z-30 mb-1.5 max-w-md whitespace-pre-wrap break-words rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-neutral-100 opacity-0 shadow-lg transition-opacity duration-100 group-hover/bar:visible group-hover/bar:opacity-100">
                        {{ $detail }}
                    </div>
                @endif
            </div>
            <div class="absolute top-1/2 flex -translate-y-1/2 items-baseline gap-1.5 whitespace-nowrap"
                 style="left: calc({{ $labelLeft }}% + 8px)">
                <span class="font-mono text-[11px] font-medium text-neutral-700 dark:text-neutral-200">{{ $badge }}</span>
                <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}</span>
                <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $detailShort }}</span>
            </div>
        @endif
    </div>
</div>
