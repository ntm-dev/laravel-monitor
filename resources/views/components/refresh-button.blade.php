{{-- Card header refresh button. Calls Card::refreshNow() (not $refresh) so tables can show their skeleton.
     Clicks are ignored while the monitorPolling store (layout.blade.php) reports a request in flight.
     `spinning` clears only on animationiteration, so the icon always finishes a full turn. --}}
<button type="button" data-tooltip="{{ __('monitor::messages.common.refresh') }}"
        x-data="{ spinning: false }"
        x-init="$watch('$store.monitorPolling.active', (active) => { if (active) spinning = true })"
        @click="$store.monitorPolling.active || $wire.refreshNow()"
        class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::REFRESH" :stroke="1.8" class="h-3.5 w-3.5"
                      x-bind:class="{ 'animate-spin': spinning }"
                      x-on:animationiteration="if (! $store.monitorPolling.active) spinning = false"/>
</button>
