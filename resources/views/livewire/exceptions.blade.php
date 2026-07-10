<div wire:poll.10s class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <h2 class="font-semibold text-sm mb-3">Exceptions</h2>

    @if ($exceptions->isEmpty())
        <p class="text-sm text-gray-600 py-6 text-center">No exceptions in this period.</p>
    @else
        <div class="space-y-2">
            @foreach ($exceptions as $exception)
                <div class="rounded-lg bg-gray-950/60 border border-red-900/30 p-2.5">
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-mono text-xs text-red-400 break-all">{{ class_basename($exception->key) }}</p>
                        <span class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-red-500/10 text-red-400">{{ number_format($exception->count) }}×</span>
                    </div>
                    @if (($exception->latest['message'] ?? '') !== '')
                        <p class="mt-1 text-xs text-gray-400 line-clamp-2">{{ $exception->latest['message'] }}</p>
                    @endif
                    <div class="mt-1.5 flex items-center gap-3 text-xs text-gray-600">
                        @if (isset($exception->latest['file']))
                            <span class="font-mono truncate">{{ $exception->latest['file'] }}:{{ $exception->latest['line'] ?? '' }}</span>
                        @endif
                        <span class="ml-auto shrink-0">{{ $exception->last_seen?->diffForHumans(short: true) }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
