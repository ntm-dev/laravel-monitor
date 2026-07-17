@props(['path', 'stroke' => 1.6, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'transform' => null])
<svg {{ $attributes->merge(['class' => 'h-4 w-4']) }} fill="{{ $fill }}" viewBox="{{ $viewBox }}" stroke-width="{{ $stroke }}" stroke="{{ $fill === 'none' ? 'currentColor' : 'none' }}">
    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}" @if($transform) transform="{{ $transform }}" @endif/>
</svg>
