@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        @if ($requests->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.outgoing')" :message="__('monitor::messages.common.no_outgoing_requests')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.endpoint') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.count') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.errors') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.avg') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                        @foreach ($requests as $request)
                            <tr class="rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset">
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
