{{-- Two-path expand/collapse icon from Laravel's exception renderer (x-monitor::icon takes a single path).
     "up-down" means expandable; "down-up" means collapsible. --}}
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
