{{-- Client-side countdown to $at (e.g. a scheduled task's next run), ticking off the shared
     monitorClock store so every countdown updates in step. Shows up to three non-zero locale units.
     wire:key includes the target so a new $at remounts x-data; `scope` keeps sibling keys unique. --}}
@props(['at' => null, 'fallback' => '—', 'scope' => ''])
@if ($at === null)
    <span {{ $attributes }}>{{ $fallback }}</span>
@else
    @php($target = $at->getTimestamp())
    <span wire:key="countdown-{{ md5($scope) }}-{{ $target }}" {{ $attributes }}
          x-data="{
              target: {{ $target }},
              units: @js(\LaravelMonitor\Support\Format::durationUnits()),
              {{-- Once due, request one Livewire refresh to fetch the next run time. --}}
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
          data-tooltip="{{ \LaravelMonitor\Support\Format::datetime($at) }} {{ \LaravelMonitor\Support\Format::timezone() }}"></span>
@endif
