<div wire:poll.10s class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold text-sm">Logs</h2>
        <select wire:model.live="level" class="bg-gray-800 border border-gray-700 rounded-lg text-xs px-2 py-1 text-gray-300">
            <option value="">All levels</option>
            @foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'] as $option)
                <option value="{{ $option }}">{{ ucfirst($option) }}</option>
            @endforeach
        </select>
    </div>

    @if ($logs->isEmpty())
        <p class="text-sm text-gray-600 py-6 text-center">No log entries in this period.</p>
    @else
        <div class="space-y-1.5">
            @foreach ($logs as $log)
                @php($level = $log->payload['level'] ?? $log->subtype ?? 'info')
                <div class="flex items-start gap-2 text-xs rounded-lg bg-gray-950/60 border border-gray-800/60 px-2.5 py-2">
                    <span @class([
                        'shrink-0 px-1.5 py-0.5 rounded uppercase text-[10px] font-semibold tracking-wide',
                        'bg-red-500/10 text-red-400' => in_array($level, ['emergency', 'alert', 'critical', 'error']),
                        'bg-amber-500/10 text-amber-400' => $level === 'warning',
                        'bg-sky-500/10 text-sky-400' => in_array($level, ['notice', 'info']),
                        'bg-gray-500/10 text-gray-400' => $level === 'debug',
                    ])>{{ $level }}</span>
                    <span class="text-gray-300 break-all">{{ $log->payload['message'] ?? $log->key }}</span>
                    <span class="ml-auto text-gray-600 shrink-0">{{ $log->created_at->diffForHumans(short: true) }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
