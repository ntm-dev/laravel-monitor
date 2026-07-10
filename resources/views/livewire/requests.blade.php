@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::REQUESTS" title="Requests">
        <x-slot:actions>
            <select wire:model.live="orderBy" class="h-8 rounded-md border border-neutral-200 bg-white px-2 text-xs text-neutral-600 shadow-sm focus:outline-none">
                <option value="count">Most hit</option>
                <option value="avg_duration">Slowest (avg)</option>
                <option value="max_duration">Slowest (max)</option>
            </select>
        </x-slot:actions>

        @if ($routes->isEmpty())
            <x-monitor::empty-state label="Requests" message="No requests recorded" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 text-left font-mono text-xs uppercase tracking-tight text-neutral-500">
                            <th class="pb-2 font-normal">Route</th>
                            <th class="pb-2 text-right font-normal">Count</th>
                            <th class="pb-2 text-right font-normal">Avg</th>
                            <th class="pb-2 text-right font-normal">Max</th>
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($routes as $route)
                            <tr class="group cursor-pointer hover:bg-neutral-50"
                                onclick="window.location='{{ route('monitor.dashboard', ['tab' => 'requests', 'key' => $route->key] + $range) }}'">
                                <td class="max-w-[16rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700" title="{{ $route->key }}">{{ $route->key }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600">{{ number_format($route->count) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600">{{ $fmt($route->avg_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($route->max_duration ?? 0) >= $threshold ? 'text-amber-600' : 'text-neutral-600' }}">{{ $fmt($route->max_duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 group-hover:border-neutral-200 group-hover:bg-white group-hover:text-neutral-600 group-hover:shadow-sm">
                                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
