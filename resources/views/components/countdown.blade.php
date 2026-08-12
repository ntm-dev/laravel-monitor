{{-- Live-ticking countdown to a future moment, e.g. a scheduled task's next run.

     `at` is a DateTimeInterface (or null). The remaining time is computed and
     rendered entirely client-side so it keeps ticking every second between
     Livewire's much slower wire:poll refreshes.

     All countdowns on the page share one Alpine.store('monitorClock') tick
     rather than each running its own setInterval: two independent timers
     started a few milliseconds apart drift out of phase for as long as both
     run — nothing resyncs them — so a table of countdowns would visibly
     count down out of step with each other, worse right after a sort/filter
     re-render mounts some rows fresh while others keep ticking on their old
     schedule. Reading off one shared, page-global "now" makes every
     countdown update on the exact same tick, by construction. Only the first
     instance to mount starts the interval; Alpine.store persists across
     Livewire re-renders (and across wire:navigate) so later instances just
     find it already there.

     Renders as "15s", "4m 3s", "2h 3m 20s" — dropping zero units and the noise
     below the three largest ones ("3d 2h 5m", never "3d 2h 5m 41s"). The unit
     suffixes come from Carbon via Format::durationUnits(), so they follow the
     dashboard locale ("2 giờ 3 phút 20 giây" in Vietnamese) — unlike
     Format::duration(), whose suffixes are part of a fixed compact notation.

     wire:key is the target timestamp: Alpine's morph plugin preserves an
     existing x-data's reactive value across a Livewire update rather than
     re-evaluating the expression, so without this the countdown would stay
     anchored to the first target it ever saw and run past zero into negatives
     once the task actually ran. See components/line-chart.blade.php.

     `scope` is whatever makes this countdown unique among its siblings (a row
     key, say) — two rows due at the same second would otherwise collide on
     that key, which Livewire's morph treats as the same node. --}}
@props(['at' => null, 'fallback' => '—', 'scope' => ''])
@if ($at === null)
    <span {{ $attributes }}>{{ $fallback }}</span>
@else
    @php($target = $at->getTimestamp())
    <span wire:key="countdown-{{ md5($scope) }}-{{ $target }}" {{ $attributes }}
          x-data="{
              target: {{ $target }},
              units: @js(\LaravelMonitor\Support\Format::durationUnits()),
              // Guards the setTimeout below so it fires once per due countdown,
              // not on every tick 'due now' stays the current label. Nothing
              // client-side can compute this task's *actual* next occurrence
              // (that's the cron expression, evaluated server-side) — so
              // instead of sitting on 'due now' for however long is left
              // until this row's own wire:poll tick happens to land, ask
              // Livewire to refresh shortly after going due, swapping in a
              // fresh $at (and therefore a fresh countdown) almost at once.
              refreshRequested: false,
              refreshTimer: null,
              init() {
                  if (! Alpine.store('monitorClock')) {
                      Alpine.store('monitorClock', { now: Math.floor(Date.now() / 1000) })
                      setInterval(() => Alpine.store('monitorClock').now = Math.floor(Date.now() / 1000), 1000)
                  }
              },
              destroy() {
                  clearTimeout(this.refreshTimer)
              },
              get label() {
                  const left = this.target - this.$store.monitorClock.now

                  if (left <= 0) {
                      if (! this.refreshRequested) {
                          this.refreshRequested = true
                          this.refreshTimer = setTimeout(() => $wire.$refresh(), 1000)
                      }

                      return @js(__('monitor::messages.schedule.due_now'))
                  }

                  return [
                      [Math.floor(left / 86400), 'd'],
                      [Math.floor(left % 86400 / 3600), 'h'],
                      [Math.floor(left % 3600 / 60), 'm'],
                      [left % 60, 's'],
                  ].filter((part) => part[0] > 0)
                   .slice(0, 3)
                   .map((part) => this.units[part[1]].replace(':count', part[0]))
                   .join(' ')
              },
          }"
          x-text="label"
          title="{{ \LaravelMonitor\Support\Format::datetime($at) }} {{ \LaravelMonitor\Support\Format::timezone() }}"></span>
@endif
