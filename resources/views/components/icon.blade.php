@props(['path', 'stroke' => 1.6])
<svg {{ $attributes->merge(['class' => 'h-4 w-4']) }} fill="none" viewBox="0 0 24 24" stroke-width="{{ $stroke }}" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/>
</svg>
