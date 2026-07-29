{{-- Double-chevron expand/collapse icon, ported directly from Laravel's own
     exception renderer (vendor/laravel/framework/.../icons/chevrons-up-down.blade.php
     and chevrons-down-up.blade.php) — a two-path icon our generic
     <x-monitor::icon> (single <path>, heroicons-style) can't express, so it
     gets its own tiny component instead of a Support\Icons constant.
     "up-down" (arrows pointing apart) reads as collapsed/expandable;
     "down-up" (arrows pointing together) reads as expanded/collapsible. --}}
@props(['direction' => 'up-down'])
@if ($direction === 'down-up')
    <svg {{ $attributes->merge(['class' => 'h-3 w-2']) }} viewBox="0 0 8 12" fill="none">
        <path d="M6.75 11.0001L4 8.25012L1.25 11.0001" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M6.75 1.50012L4 4.25012L1.25 1.50012" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
@else
    <svg {{ $attributes->merge(['class' => 'h-3 w-3']) }} viewBox="0 0 12 12" fill="none">
        <path d="M8.75 8.25012L6 11.0001L3.25 8.25012" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M8.75 3.75012L6 1.00012L3.25 3.75012" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
@endif
