{{-- Right-aligned legend stat: colored pill + mono label above a big value. --}}
@props(['label', 'dot', 'value', 'color' => 'text-neutral-900 dark:text-neutral-100', 'size' => 'lg'])
<div class="text-right">
    <p class="flex items-center justify-end gap-1.5 font-mono {{ $size === 'lg' ? 'text-xs' : 'text-[10px]' }} uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
        <span class="inline-block {{ $size === 'lg' ? 'h-3' : 'h-2.5' }} w-1 rounded-full {{ $dot }}"></span> {{ $label }}
    </p>
    <p class="mt-1 font-mono {{ $size === 'lg' ? 'text-2xl' : 'text-xl' }} font-semibold leading-none {{ $color }}">{{ $value }}</p>
</div>
