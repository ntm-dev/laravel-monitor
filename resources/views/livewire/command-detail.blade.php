@php
    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $tz = \LaravelMonitor\Support\Format::timezone();
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
         x-data="{
             hoverIndex: null,
             setHoverIndex(i) { this.hoverIndex = i },
             clearHoverIndex() { this.hoverIndex = null },
         }">
        <x-monitor::card class="flex flex-col p-4">
            <x-monitor::metric label="Runs" :value="number_format($success + $failed)">
                <x-monitor::legend label="Failed" dot="bg-rose-500" :value="number_format($failed)" :color="$failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-900 dark:text-neutral-100'"/>
                <x-monitor::legend label="Success" dot="bg-emerald-500" :value="number_format($success)"/>
            </x-monitor::metric>
            <div class="mt-5">
                <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]" :series="[
                    ['label' => 'Success', 'dot' => 'bg-emerald-500', 'data' => $successBuckets],
                    ['label' => 'Failed', 'dot' => 'bg-rose-500', 'data' => $failedBuckets],
                ]"/>
            </div>
            <x-monitor::chart-footer :since="$since" :until="$until"/>
        </x-monitor::card>
        <x-monitor::duration-chart-card label="Duration" :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- Individual runs --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COMMANDS" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($entries->count()) }} {{ $entries->count() === 1 ? 'Run' : 'Runs' }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">No runs recorded in this period.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">Date</th>
                            <th class="pb-2 font-normal">Status</th>
                            <th class="pb-2 text-right font-normal">Exit Code</th>
                            <th class="pb-2 text-right font-normal">Duration</th>
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($entries as $entry)
                            @php($runUrl = ($entry->request_id ?? null) ? route('monitor.commands.runs.show', $entry->request_id) : null)
                            <tr class="{{ $runUrl ? 'group cursor-pointer' : '' }} hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                @if ($runUrl) onclick="window.location='{{ $runUrl }}'" @endif>
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ \LaravelMonitor\Support\Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                <td class="py-2 pr-3">
                                    <span @class([
                                        'rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase',
                                        'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $entry->subtype === 'success',
                                        'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400' => $entry->subtype === 'failed',
                                    ])>{{ $entry->subtype }}</span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $entry->payload['exit_code'] ?? '—' }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($entry->duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    @if ($runUrl)
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-sm">
                                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-monitor::card>
    </div>
</div>
