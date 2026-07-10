<div wire:poll.10s class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <h2 class="font-semibold text-sm mb-3">Cache</h2>

    <div class="grid grid-cols-3 gap-3 mb-3">
        <div class="rounded-lg bg-gray-950/60 border border-gray-800/60 p-2.5">
            <p class="text-xs text-gray-500">Hit rate</p>
            <p class="text-lg font-semibold {{ $hitRate !== null && $hitRate < 50 ? 'text-amber-400' : 'text-emerald-400' }}">{{ $hitRate !== null ? $hitRate.'%' : '—' }}</p>
        </div>
        <div class="rounded-lg bg-gray-950/60 border border-gray-800/60 p-2.5">
            <p class="text-xs text-gray-500">Hits / Misses</p>
            <p class="text-lg font-semibold">{{ number_format($hits) }} <span class="text-gray-600">/</span> {{ number_format($misses) }}</p>
        </div>
        <div class="rounded-lg bg-gray-950/60 border border-gray-800/60 p-2.5">
            <p class="text-xs text-gray-500">Writes</p>
            <p class="text-lg font-semibold">{{ number_format($writes) }}</p>
        </div>
    </div>

    @if ($keys->isNotEmpty())
        <p class="text-xs text-gray-500 mb-1.5">Busiest keys</p>
        <div class="space-y-1">
            @foreach ($keys as $key)
                <div class="flex items-center gap-2 text-xs">
                    <span class="font-mono text-gray-300 truncate" title="{{ $key->key }}">{{ $key->key }}</span>
                    <span class="ml-auto text-gray-500 shrink-0">{{ number_format($key->count) }}×</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
