@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::OUTGOING" title="Outgoing Requests">
        @if ($requests->isEmpty())
            <x-monitor::empty-state label="Outgoing requests" message="No outgoing requests" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">Endpoint</th>
                            <th class="pb-2 text-right font-normal">Count</th>
                            <th class="pb-2 text-right font-normal">Errors</th>
                            <th class="pb-2 text-right font-normal">Avg</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($requests as $request)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                <td class="max-w-[16rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $request->key }}">{{ $request->key }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($request->count) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $request->errors > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($request->errors) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($request->avg_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($request->avg_duration) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
