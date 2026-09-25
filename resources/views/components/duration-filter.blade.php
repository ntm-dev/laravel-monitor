{{-- start duration filter tabs --}}
@props(['active', 'counts'])
<div class="flex h-8 items-center gap-0.5 rounded-lg border border-neutral-200 bg-neutral-200/40 p-0.5 text-xs dark:border-neutral-700/50 dark:bg-neutral-800">
    @foreach ([
        'all' => __('monitor::messages.common.view_all'),
        'avg' => '≥ '.__('monitor::messages.common.avg'),
        'p95' => '≥ '.__('monitor::messages.common.p95'),
        'threshold' => '≥ '.__('monitor::messages.common.threshold'),
    ] as $filterKey => $filterLabel)
        <button type="button" wire:click="setDurationFilter('{{ $filterKey }}')"
                wire:loading.attr="disabled" wire:target="setDurationFilter('{{ $filterKey }}')"
                @class([
                    'flex h-full items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 transition-colors',
                    'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-neutral-100' => $active === $filterKey,
                    'text-neutral-600 hover:bg-neutral-200/20 dark:text-neutral-400 dark:hover:bg-neutral-900/20' => $active !== $filterKey,
                ])>
            {{ $filterLabel }}
            <span class="rounded bg-neutral-200/80 dark:bg-neutral-700/80 px-1.5 font-mono text-[10px] text-neutral-600 dark:text-neutral-300">{{ $counts[$filterKey] }}</span>
            <svg wire:loading wire:target="setDurationFilter('{{ $filterKey }}')" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
        </button>
    @endforeach
</div>
{{-- end duration filter tabs --}}
