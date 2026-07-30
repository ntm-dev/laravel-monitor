{{-- Accordion of collapsible timeline tracks: one for the root (request/job/
     command/scheduled task) and one per job it dispatched that has since
     resolved (see Http\Controllers\Concerns\MergesJobTimelines::buildTracks()).
     Mirrors Nightwatch: a queue worker can pick a job up seconds or minutes
     after dispatch, far outside the dispatching root's own — often
     sub-second — duration, so there's no single proportional time scale
     both could stay legible on at once. Instead, only ONE track is ever
     fully expanded (with its own ruler/zoom/rows — the existing
     <x-monitor::requests.timeline>, reused completely unchanged); every
     other track collapses to a single bar, positioned by real wall-clock
     offset *relative to whichever track is currently expanded* (see
     trackBarStyle() below) — expanding a different track re-anchors that
     scale to its own range instead, so a slow-to-process job's own detail
     is never squeezed by the request's comparatively tiny duration, or
     vice versa. --}}
@props(['tracks'])
<div class="space-y-1.5"
     x-data="{
         expandedTrack: '{{ $tracks[0]['id'] }}',
         tracks: {!! \Illuminate\Support\Js::from(collect($tracks)->map(fn ($track) => [
             'id' => $track['id'],
             'start' => $track['start'],
             'duration' => $track['duration'],
         ])->all()) !!},
         trackBarStyle(id) {
             const focus = this.tracks.find(t => t.id === this.expandedTrack);
             const track = this.tracks.find(t => t.id === id);
             if (! focus || ! track || focus.duration <= 0) return { left: '0%', width: '100%' };
             const left = ((track.start - focus.start) / focus.duration) * 100;
             const width = Math.max((track.duration / focus.duration) * 100, 0.5);
             return { left: left + '%', width: width + '%' };
         },
     }">
    @foreach ($tracks as $track)
        <x-monitor::card class="overflow-hidden p-0">
            <button type="button" @click="expandedTrack = '{{ $track['id'] }}'"
                    class="flex h-10 w-full items-center gap-2 px-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2"
                                  class="h-3 w-3 shrink-0 transition-transform"
                                  x-bind:class="expandedTrack === '{{ $track['id'] }}' ? '' : '-rotate-90'"/>
                <span class="shrink-0 font-mono text-[11px] font-semibold uppercase text-neutral-500 dark:text-neutral-400">{{ $track['badge'] }}</span>
                <span class="min-w-0 truncate font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $track['label'] }}">{{ $track['label'] }}</span>
                @if ($track['attempt'] ?? null)
                    <span class="shrink-0 font-mono text-[10px] text-neutral-400 dark:text-neutral-500" title="Attempt">#{{ $track['attempt'] }}</span>
                @endif
                <span class="ml-auto shrink-0 font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ \LaravelMonitor\Support\Format::duration($track['duration']) }}</span>
            </button>

            {{-- Collapsed preview: a single bar, positioned relative to
                 whichever track is currently expanded (see trackBarStyle()
                 above) — may render off either edge (clipped by
                 overflow-hidden) when the two tracks' real time ranges
                 barely overlap, which is expected, not a bug. --}}
            <div x-show="expandedTrack !== '{{ $track['id'] }}'" class="relative h-8 overflow-hidden border-t border-neutral-100 px-3 py-2 dark:border-neutral-800">
                <span class="absolute top-1/2 h-4 -translate-y-1/2 rounded {{ $track['id'] === 'root' ? 'border border-emerald-500/40 bg-emerald-500/15 dark:border-emerald-400/40 dark:bg-emerald-400/10' : 'border border-blue-500/40 bg-blue-500/15 dark:border-blue-400/40 dark:bg-blue-400/10' }}"
                      :style="'left: ' + trackBarStyle('{{ $track['id'] }}').left + '; width: ' + trackBarStyle('{{ $track['id'] }}').width"></span>
            </div>

            <div x-show="expandedTrack === '{{ $track['id'] }}'" x-cloak class="border-t border-neutral-100 dark:border-neutral-800">
                <x-monitor::requests.timeline :entries="$track['entries']" :total-duration="$track['duration']" :root-label="$track['badge']"/>
            </div>
        </x-monitor::card>
    @endforeach
</div>
