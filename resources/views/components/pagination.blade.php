{{-- Prev/next footer shared by every paginated list & detail view. Buttons
     disable and show a spinner (only on the one actually clicked) while a
     previousPage/nextPage round trip is in flight, on top of the normal
     first/last-page boundary disabling. --}}
@props(['page', 'lastPage', 'label'])
<div class="mt-3 flex items-center justify-between border-t border-neutral-100 dark:border-neutral-800 pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
    <span>{{ $label }}</span>
    <div class="flex items-center gap-1.5">
        <button type="button" wire:click="previousPage" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled($page <= 1)
                class="inline-flex items-center gap-1.5 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">
            {{ __('monitor::messages.common.prev') }}
            <svg wire:loading wire:target="previousPage" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
        </button>
        <span>{{ $page }} / {{ $lastPage }}</span>
        <button type="button" wire:click="nextPage" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled($page >= $lastPage)
                class="inline-flex items-center gap-1.5 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">
            {{ __('monitor::messages.common.next') }}
            <svg wire:loading wire:target="nextPage" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
        </button>
    </div>
</div>
