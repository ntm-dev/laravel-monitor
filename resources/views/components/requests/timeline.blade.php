{{-- Timeline: the request's lifecycle (bootstrap/middleware/controller/
     render/sending/terminating) plus every correlated event, all positioned
     on one shared 0..$totalDuration scale so bars line up across rows like
     a waterfall chart. Renders from Support\Timeline::build()'s
     TimelineEntry[] — data-driven, no event type is hardcoded past the
     color map in timeline-entry.blade.php. Supports horizontal scroll +
     zoom (Alpine `zoom`), hover tooltips and click-to-open details (Alpine
     `hoverId`/`selectedId`), all sharing one `data` map for lookups. --}}
@props(['entries', 'totalDuration'])
@php
    use LaravelMonitor\Support\Timeline as TimelineSupport;

    $byId = collect($entries)->keyBy('id');
    $request = $byId->get('request');
    $byParent = collect($entries)->groupBy(fn ($entry) => $entry->parentId ?? 'request');

    $phaseRows = collect(TimelineSupport::PHASES)
        ->map(fn ($name) => $byId->get('phase-'.$name))
        ->filter()
        ->values();

    $leftPct = fn (int $start) => $totalDuration > 0 ? min(100, max(0, ($start / $totalDuration) * 100)) : 0;
    $widthPct = fn (int $duration) => $totalDuration > 0 ? max(0.6, ($duration / $totalDuration) * 100) : 0.6;

    $tickCount = 6;
    $ticks = collect(range(0, $tickCount))->map(fn ($i) => (int) round($totalDuration * $i / $tickCount));

    $phaseIds = $phaseRows->pluck('id')->push('request');
    $orphanEvents = $byParent->get('request', collect())->reject(fn ($entry) => $phaseIds->contains($entry->id));

    $timelineData = collect($entries)->mapWithKeys(fn ($entry) => [$entry->id => [
        'type' => $entry->type,
        'label' => $entry->label,
        'start' => $entry->start,
        'duration' => $entry->duration,
        'metadata' => $entry->metadata,
    ]]);
@endphp
<x-monitor::card class="overflow-hidden p-0"
     x-data="{
         zoom: 1,
         hoverId: null,
         selectedId: null,
         data: {{ \Illuminate\Support\Js::from($timelineData) }},
         selected() { return this.selectedId !== null ? this.data[this.selectedId] : null },
     }">
    <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">Timeline</h2>
        <div class="flex items-center gap-1">
            <button type="button" @click="zoom = Math.max(1, zoom - 0.5)"
                    class="flex h-7 w-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800/50">−</button>
            <span class="w-10 text-center font-mono text-xs text-neutral-500 dark:text-neutral-400" x-text="zoom.toFixed(1) + 'x'"></span>
            <button type="button" @click="zoom = Math.min(8, zoom + 0.5)"
                    class="flex h-7 w-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800/50">+</button>
        </div>
    </div>

    <div class="overflow-x-auto">
        <div :style="'width: ' + (zoom * 100) + '%'" class="min-w-full">
            {{-- Ruler --}}
            <div class="grid grid-cols-[10rem_1fr] border-b border-neutral-100 dark:border-neutral-800">
                <div></div>
                <div class="relative h-6">
                    @foreach ($ticks as $tick)
                        <span class="absolute top-1 -translate-x-1/2 font-mono text-[10px] text-neutral-400 dark:text-neutral-500"
                              style="left: {{ $leftPct($tick) }}%">{{ \LaravelMonitor\Support\Format::duration($tick) }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Request root --}}
            <x-monitor::requests.timeline-row label="Request" entry-id="request" variant="root"
                :left="$leftPct($request->start)" :width="$widthPct($request->duration)"/>

            {{-- Lifecycle phases, each with its correlated events nested inside --}}
            @foreach ($phaseRows as $phase)
                <x-monitor::requests.timeline-row
                    :label="$phase->label" :entry-id="$phase->id" variant="{{ $phase->type }}"
                    :left="$leftPct($phase->start)" :width="$widthPct($phase->duration)"
                    :children="$byParent->get($phase->id, collect())"
                    :left-pct="$leftPct" :width-pct="$widthPct"/>
            @endforeach

            {{-- Events that didn't fall inside any recorded phase (e.g. timeline instrumentation disabled) --}}
            @if ($orphanEvents->isNotEmpty())
                <x-monitor::requests.timeline-row label="Other" entry-id="other" :show-bar="false"
                    :children="$orphanEvents" :left-pct="$leftPct" :width-pct="$widthPct"/>
            @endif
        </div>
    </div>

    {{-- Selected event detail panel --}}
    <div x-show="selectedId !== null" x-cloak x-transition
         class="border-t border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-900/50">
        <div class="flex items-center justify-between">
            <h3 class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400" x-text="selected()?.label"></h3>
            <button type="button" @click="selectedId = null" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOSE" :stroke="2" class="h-4 w-4"/>
            </button>
        </div>
        <dl class="mt-2 space-y-1">
            <template x-for="[key, value] in Object.entries(selected()?.metadata || {})" :key="key">
                <div class="flex items-start gap-3 py-0.5 text-xs">
                    <dt class="w-32 shrink-0 text-neutral-500 dark:text-neutral-400" x-text="key"></dt>
                    <dd class="min-w-0 flex-1 whitespace-pre-wrap break-words font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="typeof value === 'object' ? JSON.stringify(value) : value"></dd>
                </div>
            </template>
        </dl>
    </div>
</x-monitor::card>
