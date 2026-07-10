<div wire:poll.10s class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <h2 class="font-semibold text-sm mb-3">Slow queries</h2>

    @if ($queries->isEmpty())
        <p class="text-sm text-gray-600 py-6 text-center">No slow queries. Nice.</p>
    @else
        <div class="space-y-2">
            @foreach ($queries as $query)
                <div class="rounded-lg bg-gray-950/60 border border-gray-800/60 p-2.5">
                    <code class="block font-mono text-xs text-gray-300 break-all line-clamp-2" title="{{ $query->key }}">{{ $query->key }}</code>
                    <div class="mt-1.5 flex items-center gap-3 text-xs text-gray-500">
                        <span>{{ number_format($query->count) }}×</span>
                        <span>avg {{ $query->avg_duration !== null ? round($query->avg_duration).'ms' : '—' }}</span>
                        <span class="text-amber-400">max {{ $query->max_duration }}ms</span>
                        <span class="ml-auto">{{ $query->last_seen?->diffForHumans(short: true) }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
