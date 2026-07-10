@props(['href', 'external' => false])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'flex h-8 items-center gap-1.5 rounded-md border border-neutral-200 bg-white px-3 text-sm text-neutral-700 shadow-sm hover:bg-neutral-50']) }}>
    {{ $slot }}
    @if ($external)
        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400"/>
    @endif
</a>
