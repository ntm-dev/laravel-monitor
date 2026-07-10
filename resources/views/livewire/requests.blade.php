<div wire:poll.10s class="bg-night-900 border border-night-700/60 rounded-xl p-4">
    <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold text-sm">Requests</h2>
        <select wire:model.live="orderBy" class="bg-night-800 border border-night-600 rounded-lg text-xs px-2 py-1 text-gray-300">
            <option value="count">Most hit</option>
            <option value="avg_duration">Slowest (avg)</option>
            <option value="max_duration">Slowest (max)</option>
        </select>
    </div>

    @if ($routes->isEmpty())
        <p class="text-sm text-gray-600 py-6 text-center">No requests recorded in this period.</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 text-left">
                    <th class="pb-2 font-normal">Route</th>
                    <th class="pb-2 font-normal text-right">Count</th>
                    <th class="pb-2 font-normal text-right">Avg</th>
                    <th class="pb-2 font-normal text-right">Max</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-night-700/50">
                @foreach ($routes as $route)
                    <tr>
                        <td class="py-1.5 pr-2 font-mono text-xs text-gray-300 truncate max-w-[16rem]" title="{{ $route->key }}">{{ $route->key }}</td>
                        <td class="py-1.5 text-right text-gray-400">{{ number_format($route->count) }}</td>
                        <td class="py-1.5 text-right text-gray-400">{{ $route->avg_duration !== null ? round($route->avg_duration).'ms' : '—' }}</td>
                        <td class="py-1.5 text-right {{ ($route->max_duration ?? 0) >= 1000 ? 'text-amber-400' : 'text-gray-400' }}">{{ $route->max_duration !== null ? $route->max_duration.'ms' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
