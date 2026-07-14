{{-- TimelineTooltip: shared hover tooltip for a timeline bar. Reads from the
     Alpine `data` map (keyed by entry id) set up in timeline.blade.php, so
     it needs no server round-trip per hover. --}}
@props(['entryId'])
<div x-show="hoverId === '{{ $entryId }}'" x-cloak
     class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1.5 w-max max-w-xs -translate-x-1/2 rounded-lg bg-neutral-900 px-2.5 py-1.5 text-[11px] font-mono text-neutral-100 shadow-xl shadow-black/20">
    <p class="font-semibold" x-text="data['{{ $entryId }}']?.label"></p>
    <p class="text-neutral-400" x-text="(data['{{ $entryId }}']?.start ?? 0) + 'ms · ' + (data['{{ $entryId }}']?.duration ?? 0) + 'ms'"></p>
    <div class="absolute left-1/2 top-full h-0 w-0 -translate-x-1/2 border-4 border-transparent border-t-neutral-900"></div>
</div>
