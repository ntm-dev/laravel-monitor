{{-- Shared "$refresh" trigger for every Card/list header's actions slot.
     Click goes through $wire.$refresh() (not wire:click="$refresh") so it
     can be gated by the monitorPolling store: a click while any Livewire
     round trip is in flight is silently ignored instead of piling up a
     second pending request, and the icon spins for that same window — with
     no disabled/opacity styling, since that would read as "broken" for the
     ~1s a normal poll takes. See the monitorPolling store + Livewire.hook
     ('request', …) registered once, globally, in components/layout.blade.php. --}}
<button type="button" data-tooltip="{{ __('monitor::messages.common.refresh') }}"
        @click="$store.monitorPolling.active || $wire.$refresh()"
        class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::REFRESH" :stroke="1.8" class="h-3.5 w-3.5"
                      x-bind:class="{ 'animate-spin': $store.monitorPolling.active }"/>
</button>
