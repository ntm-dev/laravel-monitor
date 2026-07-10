<div wire:poll.10s class="bg-night-900 border border-night-700/60 rounded-xl p-4">
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Requests</p>
            <p class="text-2xl font-semibold mt-1">{{ number_format($requests->count) }}</p>
            <p class="text-xs text-gray-500 mt-0.5">avg {{ $requests->avg_duration !== null ? round($requests->avg_duration).'ms' : '—' }}</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Server errors</p>
            <p class="text-2xl font-semibold mt-1 {{ $errorRequests > 0 ? 'text-red-400' : '' }}">{{ number_format($errorRequests) }}</p>
            <p class="text-xs text-gray-500 mt-0.5">5xx responses</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Exceptions</p>
            <p class="text-2xl font-semibold mt-1 {{ $exceptions > 0 ? 'text-red-400' : '' }}">{{ number_format($exceptions) }}</p>
            <p class="text-xs text-gray-500 mt-0.5">reported</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Slow queries</p>
            <p class="text-2xl font-semibold mt-1 {{ $slowQueries > 0 ? 'text-amber-400' : '' }}">{{ number_format($slowQueries) }}</p>
            <p class="text-xs text-gray-500 mt-0.5">over threshold</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Failed jobs</p>
            <p class="text-2xl font-semibold mt-1 {{ $failedJobs > 0 ? 'text-red-400' : '' }}">{{ number_format($failedJobs) }}</p>
            <p class="text-xs text-gray-500 mt-0.5">in period</p>
        </div>
    </div>

    @php($max = max(1, max($buckets)))
    <div class="mt-5">
        <div class="flex items-center justify-between mb-2">
            <p class="text-xs text-gray-500">Request volume</p>
            <p class="text-xs text-gray-600">peak {{ number_format($max) }}/bucket</p>
        </div>
        <div class="flex items-end gap-px h-24 border-b border-night-700/60">
            @foreach ($buckets as $count)
                <div class="group relative flex-1 h-full flex items-end">
                    <div class="w-full rounded-t-[3px] {{ $count > 0 ? 'bg-violet-500/80 group-hover:bg-violet-400' : 'bg-night-700/60' }}"
                         style="height: {{ $count > 0 ? max(4, (int) ($count / $max * 100)) : 2 }}%"></div>
                    <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block whitespace-nowrap rounded-md bg-night-700 px-2 py-1 text-[11px] text-gray-200 shadow-lg">
                        {{ number_format($count) }} req
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
