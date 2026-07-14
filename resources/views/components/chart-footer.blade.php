@props(['since', 'until'])
@php($footerTz = \LaravelMonitor\Support\Format::timezone())
<div class="mt-2 flex items-center justify-between border-t border-neutral-100 dark:border-neutral-800 pt-2 font-mono text-xs text-neutral-400 dark:text-neutral-500">
    <span>{{ \LaravelMonitor\Support\Format::datetime($since) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $footerTz }}</span></span>
    <span>{{ \LaravelMonitor\Support\Format::datetime($until) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $footerTz }}</span></span>
</div>
