{{-- Floating "scroll to top" button, shown once the page has scrolled past
     $threshold px. Assumes the whole window scrolls (true for every
     dashboard page — see dashboard.blade.php's <main>), not some inner
     container. x-data lives on this static div, which Livewire's morph
     keeps in place across a wire:poll auto-refresh, so x-init only ever
     runs once (no listener piling up on every refresh). --}}
@props(['threshold' => 400])
<div x-data="{ showScrollTop: false }"
    x-init="showScrollTop = window.scrollY > {{ (int) $threshold }}; window.addEventListener('scroll', () => (showScrollTop = window.scrollY > {{ (int) $threshold }}))"
    x-show="showScrollTop" x-cloak class="fixed bottom-6 right-6 z-40">
    <button type="button" @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
        class="flex h-10 w-10 items-center justify-center rounded-full border border-neutral-200 bg-white text-neutral-500 shadow-md hover:bg-neutral-50 hover:text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-700 dark:hover:text-neutral-200"
        data-tooltip="{{ __('monitor::messages.common.scroll_to_top') }}">
        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP" class="h-5 w-5"/>
    </button>
</div>
