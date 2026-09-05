{{-- Small circular countdown shown next to a nav item, ticking down the
     seconds until that page's Livewire wire:poll.{{ $refresh }}s tick, then
     switching to an indeterminate loading spinner (number hidden) for as
     long as that request is actually in flight — using the same global
     monitorPolling flag every x-monitor::refresh-button spins on (see
     components/layout.blade.php) — before counting back down from a full
     {{ $refresh }}s once it lands.

     Every card shares the same global $refresh interval, and wire:poll is
     registered as a plain Alpine directive (js/directives/wire-poll.js calls
     Alpine's own directive() registration), processed in the exact same
     initTree() walk Alpine runs over the whole page on load as any other
     x-data — including this one. So anchoring our own countdown to "the
     moment this ring's x-data initialized" lands within a few ms of the real
     poll clock's own start.

     That free-running anchor is only good until the first request completes,
     though: the countdown itself is purely visual (it doesn't drive
     wire:poll, which keeps ticking on its own fixed setInterval regardless
     of request duration), and hiding the number for the request's duration
     would otherwise silently shrink the *next* visible countdown by however
     long that request took — drifting out of sync with the actual
     {{ $refresh }}s interval a little more on every cycle. Re-anchoring to
     "now" the moment monitorPolling.active drops back to false keeps every
     visible countdown spanning a full, undrifted {{ $refresh }}s, with the
     request's own duration shown separately as the spinner instead of eating
     into it.

     `spinning` (rather than binding animate-spin/x-show straight to
     monitorPolling.active) exists because most requests land well under the
     spin animation's 1s turn: killing the class mid-turn snaps the ring back
     to its static angle instead of completing the circle, which reads as a
     stutter rather than a spin. animationiteration only fires once a full
     turn has actually played, so checking monitorPolling.active there (and
     only clearing `spinning` if it's still false) guarantees the spinner
     always finishes the turn it's in before disappearing — one full lap
     minimum, however short the request was — instead of cutting it off
     wherever the request happened to finish. The anchor reset above still
     happens right at the real completion, though, not when the spinner
     later stops — that's what keeps the next countdown's length accurate;
     only the number's reveal is briefly held back for the spin to finish.

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
              if (! Alpine.store('monitorRefreshClock')) {
                  Alpine.store('monitorRefreshClock', { startedAt: Math.floor(Date.now() / 1000) })
              }
              this.$watch('$store.monitorPolling.active', (active) => {
                  if (active) {
                      this.spinning = true
                  } else {
                      this.$store.monitorRefreshClock.startedAt = Math.floor(Date.now() / 1000)
                  }
              })
          },
          get remaining() {
              const elapsed = (this.$store.monitorClock.now - this.$store.monitorRefreshClock.startedAt) % {{ $refresh }}

              return {{ $refresh }} - elapsed
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
