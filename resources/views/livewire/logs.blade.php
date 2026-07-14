<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::LOGS" title="Logs">
        <x-slot:actions>
            <select wire:model.live="level" class="h-8 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
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
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($logs as $log)
                        @php($level = $log->payload['level'] ?? $log->subtype ?? 'info')
                        <div class="flex items-start gap-2.5 px-3.5 py-2.5 text-xs">
                            <span @class([
                                'shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight',
                                'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400' => in_array($level, ['emergency', 'alert', 'critical', 'error']),
                                'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400' => $level === 'warning',
                                'border-sky-200 dark:border-sky-500/30 bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400' => in_array($level, ['notice', 'info']),
                                'border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 text-neutral-500 dark:text-neutral-400' => $level === 'debug',
                            ])>{{ $level }}</span>
                            <span class="break-all text-neutral-700 dark:text-neutral-200">{{ $log->payload['message'] ?? $log->key }}</span>
                            <span class="ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ $log->created_at->diffForHumans(short: true) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
