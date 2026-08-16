{{-- Which request/job/command/scheduled task an occurrence happened during, linking to that run's timeline when known. --}}
@props(['type' => null, 'label' => null, 'url' => null])
@php
    $colors = match ($type) {
        'request' => 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
        'job' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'command' => 'border-violet-200 dark:border-violet-500/30 bg-violet-50 dark:bg-violet-500/10 text-violet-600 dark:text-violet-400',
        'scheduled_task' => 'border-teal-200 dark:border-teal-500/30 bg-teal-50 dark:bg-teal-500/10 text-teal-600 dark:text-teal-400',
        default => null,
    };
@endphp
@if ($type && $colors && $url)
    <a href="{{ $url }}" class="flex items-center gap-1.5 hover:underline">
        <span class="shrink-0 rounded-md border {{ $colors }} px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-tight">{{ __('monitor::messages.exception.source_'.$type) }}</span>
        <span class="min-w-0 truncate font-mono text-xs text-neutral-600 dark:text-neutral-300" title="{{ $label }}">{{ $label ?? '—' }}</span>
    </a>
@else
    <span class="text-xs text-neutral-400 dark:text-neutral-500">—</span>
@endif
