@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::SCHEDULE" title="Scheduled Tasks">
        @if ($tasks->isEmpty())
            <x-monitor::empty-state label="Scheduled tasks" message="No scheduled task runs" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card>
                <div class="divide-y divide-neutral-100">
                    @foreach ($tasks as $task)
                        <div class="flex items-center gap-2.5 px-3.5 py-2.5 text-xs">
                            <span @class([
                                'h-2 w-2 shrink-0 rounded-full',
                                'bg-emerald-500' => $task->subtype === 'finished',
                                'bg-rose-500' => $task->subtype === 'failed',
                                'bg-neutral-300' => $task->subtype === 'skipped',
                            ])></span>
                            <span class="truncate font-mono text-neutral-700" title="{{ $task->key }}">{{ $task->key }}</span>
                            @if ($task->duration !== null)
                                <span class="shrink-0 font-mono text-neutral-400">{{ $fmt($task->duration) }}</span>
                            @endif
                            <span class="ml-auto shrink-0 font-mono text-neutral-400">{{ $task->created_at->diffForHumans(short: true) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
