<div wire:poll.10s class="bg-night-900 border border-night-700/60 rounded-xl p-4">
    <h2 class="font-semibold text-sm mb-3">Queue jobs</h2>

    @if ($jobs->isEmpty())
        <p class="text-sm text-gray-600 py-6 text-center">No jobs recorded in this period.</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 text-left">
                    <th class="pb-2 font-normal">Job</th>
                    <th class="pb-2 font-normal text-right">Queued</th>
                    <th class="pb-2 font-normal text-right">Done</th>
                    <th class="pb-2 font-normal text-right">Failed</th>
                    <th class="pb-2 font-normal text-right">Avg</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-night-700/50">
                @foreach ($jobs as $job)
                    <tr>
                        <td class="py-1.5 pr-2 font-mono text-xs text-gray-300 truncate max-w-[14rem]" title="{{ $job->key }}">{{ class_basename($job->key) }}</td>
                        <td class="py-1.5 text-right text-gray-400">{{ number_format($job->queued) }}</td>
                        <td class="py-1.5 text-right text-emerald-400">{{ number_format($job->processed) }}</td>
                        <td class="py-1.5 text-right {{ $job->failed > 0 ? 'text-red-400' : 'text-gray-600' }}">{{ number_format($job->failed) }}</td>
                        <td class="py-1.5 text-right text-gray-400">{{ $job->avg_duration !== null ? round($job->avg_duration).'ms' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
