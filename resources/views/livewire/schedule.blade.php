<div wire:poll.10s class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <h2 class="font-semibold text-sm mb-3">Scheduled tasks</h2>

    @if ($tasks->isEmpty())
        <p class="text-sm text-gray-600 py-6 text-center">No scheduled task runs in this period.</p>
    @else
        <div class="space-y-1.5">
            @foreach ($tasks as $task)
                <div class="flex items-center gap-2 text-xs rounded-lg bg-gray-950/60 border border-gray-800/60 px-2.5 py-2">
                    <span @class([
                        'h-2 w-2 rounded-full shrink-0',
                        'bg-emerald-400' => $task->subtype === 'finished',
                        'bg-red-400' => $task->subtype === 'failed',
                        'bg-gray-500' => $task->subtype === 'skipped',
                    ])></span>
                    <span class="font-mono text-gray-300 truncate" title="{{ $task->key }}">{{ $task->key }}</span>
                    @if ($task->duration !== null)
                        <span class="text-gray-500 shrink-0">{{ $task->duration }}ms</span>
                    @endif
                    <span class="ml-auto text-gray-600 shrink-0">{{ $task->created_at->diffForHumans(short: true) }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
