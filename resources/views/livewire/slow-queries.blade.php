@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::QUERIES" title="Queries">
        @if ($queries->isEmpty())
            <x-monitor::empty-state label="Slow queries" message="No slow queries detected" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card>
                <div class="divide-y divide-neutral-100">
                    @foreach ($queries as $query)
                        <div class="p-3.5">
                            <code class="line-clamp-2 block break-all font-mono text-xs text-neutral-700" title="{{ $query->key }}">{{ $query->key }}</code>
                            <div class="mt-1.5 flex items-center gap-3 font-mono text-[11px] text-neutral-400">
                                <span>{{ number_format($query->count) }}×</span>
                                <span>avg {{ $fmt($query->avg_duration) }}</span>
                                <span class="text-amber-600">max {{ $fmt($query->max_duration) }}</span>
                                <span class="ml-auto">{{ $query->last_seen?->diffForHumans(short: true) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
