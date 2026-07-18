@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::SCHEDULE" title="Scheduled Tasks">
        @if ($tasks->isEmpty())
            <x-monitor::empty-state label="Scheduled tasks" message="No scheduled task runs" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card>
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($tasks as $task)
                        <div class="flex items-center gap-2.5 px-3.5 py-2.5 text-xs">
                            <span @class([
                                'h-2 w-2 shrink-0 rounded-full',
                                'bg-emerald-500' => $task->subtype === 'finished',
                                'bg-rose-500' => $task->subtype === 'failed',
                                'bg-neutral-300 dark:bg-neutral-600' => $task->subtype === 'skipped',
                            ])></span>
                            <span class="truncate font-mono text-neutral-700 dark:text-neutral-200" title="{{ $task->payload['expression'] ?? '' }} {{ $task->payload['timezone'] ?? '' }}">{{ $task->key }}</span>
                            @if ($task->payload['without_overlapping'] ?? false)
                                <span class="shrink-0 rounded border border-amber-200 bg-amber-50 px-1 py-0.5 font-mono text-[10px] uppercase leading-tight text-amber-600 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400" title="Without overlapping">No overlap</span>
                            @endif
                            @if ($task->payload['run_in_background'] ?? false)
                                <span class="shrink-0 rounded border border-neutral-200 bg-neutral-50 px-1 py-0.5 font-mono text-[10px] uppercase leading-tight text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400" title="Runs in background">BG</span>
                            @endif
                            @if ($task->duration !== null)
                                <span class="shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ $fmt($task->duration) }}</span>
                            @endif
                            <span class="ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ $task->created_at->diffForHumans(short: true) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
