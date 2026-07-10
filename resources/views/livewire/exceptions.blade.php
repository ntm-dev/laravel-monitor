<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::EXCEPTIONS" title="Exceptions">
        @if ($exceptions->isEmpty())
            <x-monitor::empty-state label="Exceptions" message="No exceptions reported" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card>
                <div class="divide-y divide-neutral-100">
                    @foreach ($exceptions as $exception)
                        <div class="p-3.5">
                            <div class="flex items-start justify-between gap-2">
                                <p class="break-all font-mono text-xs font-medium text-rose-600">{{ class_basename($exception->key) }}</p>
                                <span class="shrink-0 rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 font-mono text-xs text-rose-600">{{ number_format($exception->count) }}×</span>
                            </div>
                            @if (($exception->latest['message'] ?? '') !== '')
                                <p class="mt-1 line-clamp-2 text-xs text-neutral-500">{{ $exception->latest['message'] }}</p>
                            @endif
                            <div class="mt-1.5 flex items-center gap-3 font-mono text-[11px] text-neutral-400">
                                @if (isset($exception->latest['file']))
                                    <span class="truncate">{{ $exception->latest['file'] }}:{{ $exception->latest['line'] ?? '' }}</span>
                                @endif
                                <span class="ml-auto shrink-0">{{ $exception->last_seen?->diffForHumans(short: true) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
