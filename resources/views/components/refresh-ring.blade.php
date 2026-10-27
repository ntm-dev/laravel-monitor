{{-- Nav-item countdown to the next auto-refresh (read from the monitorRefreshClock store), then a
     spinner while monitorPolling is active. `spinning` clears only on animationiteration, so it
     always finishes a full turn. Shares the monitorClock 1s tick with countdown.blade.php. --}}
@props(['refresh'])
<span x-data="{
          spinning: false,
          init() {
              if (! Alpine.store('monitorClock')) {
                  Alpine.store('monitorClock', { now: Math.floor(Date.now() / 1000) })
                  setInterval(() => Alpine.store('monitorClock').now = Math.floor(Date.now() / 1000), 1000)
              }
              this.$watch('$store.monitorPolling.active', (active) => { if (active) this.spinning = true })
          },
          get remaining() {
              const elapsed = this.$store.monitorClock.now - this.$store.monitorRefreshClock.startedAt

              return Math.min({{ $refresh }}, Math.max(0, {{ $refresh }} - elapsed))
          },
          {{-- Both plural forms resolved server-side; the live count picks one. --}}
          get unit() {
              return this.remaining === 1
                  ? @js(trans_choice('monitor::messages.common.second_count', 1))
                  : @js(trans_choice('monitor::messages.common.second_count', 2))
          },
      }"
      {{-- Placeholders left unreplaced in __() so .replace() fills in the live countdown. --}}
      :data-tooltip="spinning ? @js(__('monitor::messages.common.refreshing')) : @js(__('monitor::messages.nav.refresh_in')).replace(':seconds', remaining).replace(':unit', unit)"
      class="relative flex h-4 w-4 shrink-0 items-center justify-center">
    <svg viewBox="0 0 16 16" class="h-4 w-4" :class="spinning ? 'animate-spin' : '-rotate-90'"
         x-on:animationiteration="if (! $store.monitorPolling.active) spinning = false">
        <circle cx="8" cy="8" r="6.5" fill="none" stroke-width="1.5" stroke="currentColor" class="text-neutral-200 dark:text-neutral-700"/>
        <circle cx="8" cy="8" r="6.5" fill="none" stroke-width="1.5" stroke="currentColor" stroke-linecap="round"
                class="text-blue-500 dark:text-blue-400"
                stroke-dasharray="40.84"
                :stroke-dashoffset="spinning ? 30.63 : 40.84 * (1 - remaining / {{ $refresh }})"/>
    </svg>
    <span class="absolute inset-0 flex translate-y-px items-center justify-center font-mono text-[7px] leading-none text-neutral-500 dark:text-neutral-400"
          x-show="! spinning" x-text="remaining"></span>
</span>
