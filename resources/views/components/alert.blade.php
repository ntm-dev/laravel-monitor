{{-- Generic inline banner (error/success), neumorphic inset pill. --}}
@props(['color' => 'rose'])
@php
    $text = match ($color) {
        'emerald' => 'text-emerald-700 dark:text-emerald-400',
        default => 'text-rose-700 dark:text-rose-400',
    };
    $dot = match ($color) {
        'emerald' => 'bg-emerald-500',
        default => 'bg-rose-500',
    };
@endphp
<div {{ $attributes->merge(['class' => "mb-4 flex items-center gap-2 rounded-xl bg-neutral-200 px-3 py-2 text-xs shadow-neu-inset dark:bg-neutral-800 dark:shadow-neu-dark-inset {$text}"]) }}>
    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}"></span>
    {{ $slot }}
</div>
