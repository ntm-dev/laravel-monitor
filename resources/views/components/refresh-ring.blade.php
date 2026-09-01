{{-- Small circular countdown shown next to a nav item, ticking down the
     seconds until that page's Livewire wire:poll.{{ $refresh }}s tick.

     Every card shares the same global $refresh interval, and wire:poll is
     registered as a plain Alpine directive (js/directives/wire-poll.js calls
     Alpine's own directive() registration), processed in the exact same
     initTree() walk Alpine runs over the whole page on load as any other
     x-data — including this one. So anchoring our own countdown to "the
     moment this ring's x-data initialized" lands within a few ms of the real
     poll clock's own start, without reaching into Livewire's private module
     state to read it directly. Wire:poll's interval is a bare setInterval
     that free-runs from that start regardless of what else fires requests
     against the component in between (a filter click, a sort, …) — it never
     resets — so our anchor-and-modulo math tracks it exactly rather than
     drifting, without needing to special-case those requests either.

     Reuses the same Alpine.store('monitorClock') 1s heartbeat as
     components/countdown.blade.php instead of running a second timer. --}}
@props(['refresh'])
<span x-data="{
          init() {
              if (! Alpine.store('monitorClock')) {
                  Alpine.store('monitorClock', { now: Math.floor(Date.now() / 1000) })
                  setInterval(() => Alpine.store('monitorClock').now = Math.floor(Date.now() / 1000), 1000)
              }
              if (! Alpine.store('monitorRefreshClock')) {
                  Alpine.store('monitorRefreshClock', { startedAt: Math.floor(Date.now() / 1000) })
              }
          },
          get remaining() {
              const elapsed = (this.$store.monitorClock.now - this.$store.monitorRefreshClock.startedAt) % {{ $refresh }}

              return {{ $refresh }} - elapsed
          },
      }"
      {{-- Raw string still carries the literal ':seconds' token (no
           replacement passed to __()) so the reactive .replace() below can
           swap in the live countdown instead of the fixed interval. --}}
      :data-tooltip="@js(__('monitor::messages.nav.refresh_in')).replace(':seconds', remaining)"
      class="relative flex h-4 w-4 shrink-0 items-center justify-center">
    <svg viewBox="0 0 16 16" class="h-4 w-4 -rotate-90">
        <circle cx="8" cy="8" r="6.5" fill="none" stroke-width="1.5" stroke="currentColor" class="text-neutral-200 dark:text-neutral-700"/>
        <circle cx="8" cy="8" r="6.5" fill="none" stroke-width="1.5" stroke="currentColor" stroke-linecap="round"
                class="text-blue-500 dark:text-blue-400"
                stroke-dasharray="40.84"
                :stroke-dashoffset="40.84 * (1 - remaining / {{ $refresh }})"/>
    </svg>
    <span class="absolute inset-0 flex items-center justify-center font-mono text-[7px] leading-none text-neutral-500 dark:text-neutral-400" x-text="remaining"></span>
</span>
