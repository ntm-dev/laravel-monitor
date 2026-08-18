@props(['href', 'external' => false])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'flex h-8 items-center gap-1.5 rounded-xl bg-neutral-200 dark:bg-neutral-800 px-3 text-sm text-neutral-700 dark:text-neutral-200 shadow-neu-sm dark:shadow-neu-dark-sm transition-shadow hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset']) }}>
    {{ $slot }}
    @if ($external)
        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500"/>
    @endif
</a>
