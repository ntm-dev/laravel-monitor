{{-- Legend stat: coloured pill + mono label above a big value. A series-key makes it toggle that
     series via the card's `hidden` x-data; without one it is a plain stat. --}}
@props(['label', 'dot', 'value', 'color' => 'text-neutral-900 dark:text-neutral-100', 'size' => 'lg', 'seriesKey' => null])
<div class="text-right {{ $seriesKey !== null ? 'cursor-pointer select-none transition-opacity' : '' }}"
     @if ($seriesKey !== null)
         role="button" tabindex="0"
         @click="hidden['{{ $seriesKey }}'] = !hidden['{{ $seriesKey }}']"
         @keydown.enter="hidden['{{ $seriesKey }}'] = !hidden['{{ $seriesKey }}']"
         @keydown.space.prevent="hidden['{{ $seriesKey }}'] = !hidden['{{ $seriesKey }}']"
         :class="{ 'opacity-40': hidden['{{ $seriesKey }}'] }"
     @endif>
    <p class="flex items-center justify-end gap-1.5 font-mono {{ $size === 'lg' ? 'text-xs' : 'text-[10px]' }} uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
        <span class="inline-block {{ $size === 'lg' ? 'h-3' : 'h-2.5' }} w-1 rounded-full {{ $dot }}"></span> {{ $label }}
    </p>
    <p class="mt-1 font-mono {{ $size === 'lg' ? 'text-2xl' : 'text-xl' }} font-semibold leading-none {{ $color }}">{{ $value }}</p>
</div>
