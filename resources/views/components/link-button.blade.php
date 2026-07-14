@props(['href', 'external' => false])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'flex h-8 items-center gap-1.5 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-3 text-sm text-neutral-700 dark:text-neutral-200 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50']) }}>
    {{ $slot }}
    @if ($external)
        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500"/>
    @endif
</a>
