<div wire:poll.10s class="bg-night-900 border border-night-700/60 rounded-xl p-4">
    <h2 class="font-semibold text-sm mb-3">Outgoing HTTP</h2>

    @if ($requests->isEmpty())
        <p class="text-sm text-gray-600 py-6 text-center">No outgoing requests in this period.</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 text-left">
                    <th class="pb-2 font-normal">Endpoint</th>
                    <th class="pb-2 font-normal text-right">Count</th>
                    <th class="pb-2 font-normal text-right">Errors</th>
                    <th class="pb-2 font-normal text-right">Avg</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-night-700/50">
                @foreach ($requests as $request)
                    <tr>
                        <td class="py-1.5 pr-2 font-mono text-xs text-gray-300 truncate max-w-[16rem]" title="{{ $request->key }}">{{ $request->key }}</td>
                        <td class="py-1.5 text-right text-gray-400">{{ number_format($request->count) }}</td>
                        <td class="py-1.5 text-right {{ $request->errors > 0 ? 'text-red-400' : 'text-gray-600' }}">{{ number_format($request->errors) }}</td>
                        <td class="py-1.5 text-right text-gray-400">{{ $request->avg_duration !== null ? round($request->avg_duration).'ms' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
