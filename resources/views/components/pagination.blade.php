{{-- Prev/next footer shared by every paginated list & detail view. Buttons
     disable and show a spinner (only on the one actually clicked) while a
     previousPage/nextPage round trip is in flight, on top of the normal
     first/last-page boundary disabling. --}}
@props(['page', 'lastPage', 'label'])
<div class="mt-3 flex items-center justify-between shadow-[0_-1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_-1px_0_rgba(255,255,255,0.06)] pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
    <span>{{ $label }}</span>
    <div class="flex items-center gap-1.5">
        <button type="button" wire:click="previousPage" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled($page <= 1)
                class="inline-flex items-center gap-1.5 rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2.5 py-1 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset disabled:opacity-40 disabled:shadow-none disabled:hover:shadow-none">
            {{ __('monitor::messages.common.prev') }}
            <svg wire:loading wire:target="previousPage" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
        </button>
        <span>{{ $page }} / {{ $lastPage }}</span>
        <button type="button" wire:click="nextPage" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled($page >= $lastPage)
                class="inline-flex items-center gap-1.5 rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2.5 py-1 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset disabled:opacity-40 disabled:shadow-none disabled:hover:shadow-none">
            {{ __('monitor::messages.common.next') }}
            <svg wire:loading wire:target="nextPage" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
        </button>
    </div>
</div>
