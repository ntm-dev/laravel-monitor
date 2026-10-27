{{-- One waterfall row, rendered once per pane: the tree label (part="label") or the chart bar (part="bar").
     Hover syncs across panes via `hoveredId`; only detailable rows get the selected highlight.
     Label indentation is drawn as one vertical guide line per depth level. --}}
@php
    $depth = match ($kind) { 'phase', 'attempt' => 1, 'event' => 2, default => 0 };
    $highlightClass = $detailable
        ? "selectedId === '{$entry->id}' ? 'bg-blue-50/60 dark:bg-blue-500/5' : (hoveredId === '{$entry->id}' ? 'bg-neutral-50 dark:bg-neutral-800/60' : '')"
        : "hoveredId === '{$entry->id}' ? 'bg-neutral-50 dark:bg-neutral-800/60' : ''";
    // Tree-pane hover tooltip text (root and events only); empty skips showTooltip().
    $tooltipText = match (true) {
        $kind === 'root' => $entry->label,
        $kind === 'event' => $tooltipDetail,
        default => '',
    };
    // Root and attempt rows always show; the rest only while their track is expanded.
    $visible = in_array($kind, ['root', 'attempt'], true) ? 'true' : "expandedTracks['{$trackId}']";
    $toggleClick = "toggleTrack('{$trackId}')";
    // Clicking a root row's body switches request-detail-page's `activeInfo` to this track
    // and updates the URL and title (layout's format) without a reload. Null when $jobUrl is.
    $navigateClick = $jobUrl !== null
        ? sprintf(
            "if (activeInfo !== %1\$s) { activeInfo = %1\$s; history.pushState(null, '', %2\$s); document.title = (infoTitles[%1\$s] || '') + ' — Monitor'; }",
            json_encode($trackId),
            json_encode($jobUrl),
        )
        : null;
@endphp
@if ($part === 'label')
    <div x-show="{{ $visible }}"
         @class(['relative flex h-9 min-w-0 items-center pr-3', 'cursor-pointer' => $detailable || $navigateClick || $scrollable])
         :class="{{ $highlightClass }}"
         @mouseenter="hoveredId = '{{ $entry->id }}'; showTooltip($event, @js($tooltipText))"
         @mouseleave="hoveredId = null; hideTooltip()"
         @if ($detailable) @click="selectRow('{{ $entry->id }}')"
         @elseif ($navigateClick) @click="{{ $navigateClick }}"
         @elseif ($scrollable) @click="scrollToBar('{{ $entry->id }}')" @endif>
        @for ($i = 0; $i < $depth; $i++)
            <span class="h-9 w-4 shrink-0 border-l ml-2 border-neutral-300 dark:border-neutral-700"></span>
        @endfor
        <div class="flex min-w-0 translate-y-px items-center gap-1.5 {{ $depth > 0 ? 'pl-2' : 'pl-3' }}">
            @if ($kind === 'root')
                @if ($focusable)
                    {{-- The chevron alone toggles the track; stopping propagation keeps the
                         row's own $navigateClick handler from also firing. --}}
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2"
                                      class="h-3 w-3 shrink-0 cursor-pointer text-neutral-400 transition-transform dark:text-neutral-500"
                                      x-bind:class="expandedTracks['{{ $trackId }}'] ? '' : '-rotate-90'"
                                      @click.stop="{{ $toggleClick }}"/>
                @endif
                <span class="font-mono text-[11px] font-semibold text-neutral-800 dark:text-neutral-100">{{ $rootLabel }}</span>
                <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $entry->label }}</span>
            @elseif ($kind === 'attempt')
                <span class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.attempt') }} #{{ $attempt }}</span>
            @elseif ($kind === 'phase')
                <span class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-600 dark:text-neutral-300">{{ $entry->label }}</span>
                @if ($entry->metadata['controller'] ?? null)
                    <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500" data-tooltip="{{ $entry->metadata['controller'] }}">{{ $entry->metadata['controller'] }}</span>
                @endif
                @if ($entry->metadata['render']['name'] ?? null)
                    <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500" data-tooltip="{{ $entry->metadata['render']['name'] }}">{{ $entry->metadata['render']['name'] }}</span>
                @endif
            @else
                @if ($duplicateColor)
                    <span class="relative flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-full border border-{{ $duplicateColor }}-500 pl-px text-[8px] font-bold leading-none text-{{ $duplicateColor }}-500 dark:border-{{ $duplicateColor }}-400 dark:text-{{ $duplicateColor }}-400"
                          :class="(heartbeatActive || heartbeatGroup === @js($duplicateGroup)) ? 'monitor-heartbeat' : ''">D</span>
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
    {{-- data-duplicate-group: the heartbeat handler in timeline.blade.php scrolls to the first match. --}}
    <div x-show="{{ $visible }}" class="relative flex h-9 items-center" :class="{{ $highlightClass }}" @if ($duplicateColor) data-duplicate-group @endif>
        <div @class(['relative flex h-full items-center', 'cursor-pointer' => $detailable || $navigateClick || $scrollable, 'scroll-mt-[169px]' => $kind === 'root'])
             style="margin-left: {{ $left }}%; width: {{ $width }}%; min-width: 3px" data-row-id="{{ $entry->id }}"
             @if ($kind === 'root') data-track-root="{{ $trackId }}" @endif
             @mouseenter="hoveredId = '{{ $entry->id }}'" @mouseleave="hoveredId = null"
             @if ($detailable) @click="selectRow('{{ $entry->id }}')"
             @elseif ($navigateClick) @click="{{ $navigateClick }}"
             @elseif ($scrollable) @click="scrollToBar('{{ $entry->id }}')" @endif>
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
                {{-- Coloured by the attempt's outcome; the job root stays neutral. --}}
                <span class="absolute left-0 top-1/2 h-7 w-full -translate-y-1/2 rounded {{ $rootColor }}"></span>
                <div class="sticky left-0 z-10 flex h-6 translate-y-px items-center gap-1.5 whitespace-nowrap px-2">
                    <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.attempt') }} #{{ $attempt }}</span>
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
                        <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $durationLabel }}@if ($entry->metadata['percent'] ?? null) ({{ $entry->metadata['percent'] }})@endif</span>
                    @endif
                    @if ($entry->metadata['controller'] ?? null)
                        <span class="max-w-sm truncate font-mono text-[11px] text-neutral-500 dark:text-neutral-400" data-tooltip="{{ $entry->metadata['controller'] }}">{{ $entry->metadata['controller'] }}</span>
                    @endif
                    @if ($entry->metadata['render']['name'] ?? null)
                        <span class="max-w-sm truncate font-mono text-[11px] text-neutral-500 dark:text-neutral-400" data-tooltip="{{ $entry->metadata['render']['name'] }}">{{ $entry->metadata['render']['name'] }}</span>
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
