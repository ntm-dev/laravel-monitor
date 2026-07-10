<div wire:poll.10s class="bg-gray-900 border border-gray-800 rounded-xl p-4">
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
    <div class="mt-4 flex items-end gap-px h-16" title="Request volume">
        @foreach ($buckets as $count)
            <div class="flex-1 rounded-t bg-indigo-500/70" style="height: {{ $count > 0 ? max(4, (int) ($count / $max * 100)) : 2 }}%"></div>
        @endforeach
    </div>
</div>
