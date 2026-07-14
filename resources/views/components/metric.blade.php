{{-- Card header: mono label + big value on the left, legend slot on the right. --}}
@props(['label', 'value', 'size' => 'lg'])
<div class="flex items-start justify-between gap-4">
    <div>
        <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $label }}</p>
        <p class="mt-1 {{ $size === 'lg' ? 'text-2xl' : 'font-mono text-xl' }} font-semibold leading-none text-neutral-900 dark:text-neutral-100">{{ $value }}</p>
    </div>
    <div class="flex {{ $size === 'lg' ? 'gap-6' : 'gap-4' }}">{{ $slot }}</div>
</div>
