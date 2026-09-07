{{-- Small circular countdown shown next to a nav item, ticking down the
     seconds until the next auto-refresh, then switching to an indeterminate
     loading spinner (number hidden) for as long as that request is actually
     in flight — using the same global monitorPolling flag every
     x-monitor::refresh-button spins on (see components/layout.blade.php) —
     before counting back down from a full {{ $refresh }}s once it lands.

     Reads the same Alpine.store('monitorRefreshClock') the auto-refresh timer
     in layout.blade.php re-arms off, so the number shown here is the timer,
     not a parallel guess at it.

     `spinning` (rather than binding animate-spin/x-show straight to
     monitorPolling.active) exists because most requests land well under the
     spin animation's 1s turn: killing the class mid-turn snaps the ring back
     to its static angle instead of completing the circle, which reads as a
     stutter rather than a spin. animationiteration only fires once a full
     turn has actually played, so checking monitorPolling.active there (and
     only clearing `spinning` if it's still false) guarantees the spinner
     always finishes the turn it's in before disappearing — one full lap
     minimum, however short the request was — instead of cutting it off
     wherever the request happened to finish. Only the number's reveal is
     held back for the spin; the clock itself re-anchors at the real
     completion, so the next countdown's length stays accurate.

     Reuses the same Alpine.store('monitorClock') 1s heartbeat as
     components/countdown.blade.php instead of running a second timer. --}}
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
          {{-- remaining ticks client-side, so 'second' vs 'seconds' can't be
               picked once server-side the way trans_choice normally would —
               both forms are resolved here, once, and the live remaining
               value just chooses between them on every tick. --}}
          get unit() {
              return this.remaining === 1
                  ? @js(trans_choice('monitor::messages.common.second_count', 1))
                  : @js(trans_choice('monitor::messages.common.second_count', 2))
          },
      }"
      {{-- Raw string still carries the literal ':seconds'/':unit' tokens (no
           replacement passed to __()) so the reactive .replace() below can
           swap in the live countdown instead of the fixed interval. --}}
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
