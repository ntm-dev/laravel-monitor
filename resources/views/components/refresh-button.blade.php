{{-- Shared refresh trigger for every Card/list header's actions slot.
     Calls Card::refreshNow(), not the built-in $refresh, so the tables can
     tell a hand-clicked refresh from the silent auto-refresh and show their
     skeleton for one but not the other (see Card::refreshNow()).

     Driven from @click rather than wire:click so it can be gated by the
     monitorPolling store: a click while any Livewire round trip is in flight
     is silently ignored instead of piling up a
     second pending request, and the icon spins for that same window — with
     no disabled/opacity styling, since that would read as "broken" for the
     ~1s a normal poll takes. See the monitorPolling store + Livewire.hook
     ('request', …) registered once, globally, in components/layout.blade.php.

     `spinning` (rather than binding animate-spin straight to
     monitorPolling.active, as this used to) exists for the same reason as
     refresh-ring's own `spinning` flag: most requests land well under the
     spin animation's 1s turn, so killing the class the instant the request
     finishes snaps the icon back to its resting angle mid-turn instead of
     completing the circle. animationiteration only fires once a full turn
     has actually played, so only clearing `spinning` there — and only if
     monitorPolling.active is still false at that point — guarantees one
     full lap minimum, however short the request was, instead of cutting it
     off wherever the request happened to finish. --}}
<button type="button" data-tooltip="{{ __('monitor::messages.common.refresh') }}"
        x-data="{ spinning: false }"
        x-init="$watch('$store.monitorPolling.active', (active) => { if (active) spinning = true })"
        @click="$store.monitorPolling.active || $wire.refreshNow()"
        class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::REFRESH" :stroke="1.8" class="h-3.5 w-3.5"
                      x-bind:class="{ 'animate-spin': spinning }"
                      x-on:animationiteration="if (! $store.monitorPolling.active) spinning = false"/>
</button>
