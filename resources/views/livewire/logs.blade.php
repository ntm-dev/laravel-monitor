<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::LOGS" title="Logs">
        <x-slot:actions>
            <select wire:model.live="level" class="h-8 rounded-md border border-neutral-200 bg-white px-2 text-xs text-neutral-600 shadow-sm focus:outline-none">
                <option value="">All levels</option>
                @foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'] as $option)
                    <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </x-slot:actions>

        @if ($logs->isEmpty())
            <x-monitor::empty-state label="Logs" message="No log entries" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card>
                <div class="divide-y divide-neutral-100">
                    @foreach ($logs as $log)
                        @php($level = $log->payload['level'] ?? $log->subtype ?? 'info')
                        <div class="flex items-start gap-2.5 px-3.5 py-2.5 text-xs">
                            <span @class([
                                'shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight',
                                'border-rose-200 bg-rose-50 text-rose-600' => in_array($level, ['emergency', 'alert', 'critical', 'error']),
                                'border-amber-200 bg-amber-50 text-amber-600' => $level === 'warning',
                                'border-sky-200 bg-sky-50 text-sky-600' => in_array($level, ['notice', 'info']),
                                'border-neutral-200 bg-neutral-50 text-neutral-500' => $level === 'debug',
                            ])>{{ $level }}</span>
                            <span class="break-all text-neutral-700">{{ $log->payload['message'] ?? $log->key }}</span>
                            <span class="ml-auto shrink-0 font-mono text-neutral-400">{{ $log->created_at->diffForHumans(short: true) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
