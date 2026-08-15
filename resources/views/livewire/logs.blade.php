<div wire:poll.{{ $refresh }}s>

    <select wire:model.live="level"
        class="h-8 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
        <option value="">{{ __('monitor::messages.common.all_levels') }}</option>
        @foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'] as $option)
            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
        @endforeach
    </select>

    @if ($logs->isEmpty())
        <x-monitor::empty-state :label="__('monitor::messages.nav.logs')" :message="__('monitor::messages.common.no_log_entries')" :period-phrase="$periodPhrase" />
    @else
        <div class="divide-y divide-neutral-100 dark:divide-neutral-800  mt-2">
            @foreach ($logs as $log)
                @php($level = $log->subtype ?? 'info')
                @php($contextRaw = $log->payload['context'] ?? '{}')
                @php($contextDecoded = json_decode($contextRaw, true))
                @php($contextPretty = json_last_error() === JSON_ERROR_NONE ? json_encode($contextDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $contextRaw)
                @php($message = $log->payload['message'] ?? '')
                @php($summary = $message !== '' ? $message : \Illuminate\Support\Str::limit(str_replace(["\r\n", "\n", "\r"], ' ', $contextRaw), 200))
                {{-- start log entry row --}}
                <div x-data="{ expanded: false }" class="mt-1">
                    <div class="flex cursor-pointer items-start rounded-md border bg-white gap-2.5 px-3.5 py-2.5 text-xs" @click="expanded = ! expanded">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
                            :class="expanded ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'">
                            <x-monitor::chevrons-updown x-show="expanded" direction="down-up"/>
                            <x-monitor::chevrons-updown x-show="! expanded" x-cloak direction="up-down"/>
                        </span>
                        <span class="self-center shrink-0 font-mono text-neutral-400 dark:text-neutral-500">
                            {{ \LaravelMonitor\Support\Format::datetime($log->created_at, \LaravelMonitor\Support\Format::DATETIME_PRECISE) }}
                        </span>
                        <span @class([
                            'shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight',
                            'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400' => in_array(
                                $level,
                                ['emergency', 'alert', 'critical', 'error']),
                            'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400' =>
                                $level === 'warning',
                            'border-sky-200 dark:border-sky-500/30 bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400' => in_array(
                                $level,
                                ['notice', 'info']),
                            'border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 text-neutral-500 dark:text-neutral-400' =>
                                $level === 'debug',
                        ])>{{ $level }}</span>
                        @if ($log->sourceUrl)
                            <span class="max-w-[14rem] shrink-0" @click.stop>
                                <x-monitor::exception-source-badge :type="$log->sourceType" :label="$log->sourceLabel"
                                    :url="$log->sourceUrl" />
                            </span>
                        @endif
                        <span class="self-center min-w-0 flex-1 truncate text-neutral-700 dark:text-neutral-200"
                            title="{{ $summary }}">{{ $summary }}</span>
                        <span
                            class="self-center ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ $log->created_at->diffForHumans(short: true) }}</span>
                    </div>
                    <div x-show="expanded" x-cloak
                        class="space-y-3 border-t border-neutral-100 bg-neutral-50/60 px-3.5 py-3 pl-9 dark:border-neutral-800 dark:bg-neutral-900/40">
                        <div>
                            <div
                                class="mb-1 text-[10px] font-medium uppercase tracking-tight text-neutral-400 dark:text-neutral-500">
                                {{ __('monitor::messages.logs.context') }}</div>
                            <pre
                                class="max-h-64 overflow-auto rounded-md border border-neutral-200 bg-white p-2 font-mono text-[11px] leading-relaxed text-neutral-700 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-200"><code>{{ $contextPretty }}</code></pre>
                        </div>
                    </div>
                </div>
                {{-- end log entry row --}}
            @endforeach
        </div>
    @endif
</div>
