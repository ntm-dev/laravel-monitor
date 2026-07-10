@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::OUTGOING" title="Outgoing Requests">
        @if ($requests->isEmpty())
            <x-monitor::empty-state label="Outgoing requests" message="No outgoing requests" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 text-left font-mono text-xs uppercase tracking-tight text-neutral-500">
                            <th class="pb-2 font-normal">Endpoint</th>
                            <th class="pb-2 text-right font-normal">Count</th>
                            <th class="pb-2 text-right font-normal">Errors</th>
                            <th class="pb-2 text-right font-normal">Avg</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($requests as $request)
                            <tr class="hover:bg-neutral-50">
                                <td class="max-w-[16rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700" title="{{ $request->key }}">{{ $request->key }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600">{{ number_format($request->count) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $request->errors > 0 ? 'text-rose-600' : 'text-neutral-300' }}">{{ number_format($request->errors) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600">{{ $fmt($request->avg_duration) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
