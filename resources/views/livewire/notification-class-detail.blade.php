@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
         x-data="{
             hoverIndex: null,
             setHoverIndex(i) { this.hoverIndex = i },
             clearHoverIndex() { this.hoverIndex = null },
         }">
        <x-monitor::card class="flex flex-col p-4">
            <x-monitor::metric label="Notifications" :value="number_format($total)">
                @foreach ($channels as $channel)
                    <x-monitor::legend :label="$channel->label" :dot="$channel->dot" :value="number_format($channel->count)"/>
                @endforeach
            </x-monitor::metric>
            <div class="mt-5">
                <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]"
                    :series="[['label' => 'Notifications', 'dot' => 'bg-blue-500', 'data' => $volumeBuckets]]"/>
            </div>
            <x-monitor::chart-footer :since="$since" :until="$until"/>
        </x-monitor::card>
        <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- Individual sends --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="Icons::NOTIFICATIONS" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($entries->count()) }} {{ $entries->count() === 1 ? 'Send' : 'Sends' }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">No sends recorded in this period.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">Date</th>
                            <th class="pb-2 font-normal">Notifiable</th>
                            <th class="pb-2 font-normal">Channel</th>
                            <th class="pb-2 text-right font-normal">Duration</th>
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($entries as $entry)
                            @php($url = $entry->timeline_url ?? route('monitor.dashboard', ['tab' => 'notifications', 'key' => $entry->id] + $range))
                            <tr class="group cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                onclick="window.location='{{ $url }}'">
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                <td class="max-w-[16rem] truncate py-2 pr-3 font-mono text-xs text-neutral-500 dark:text-neutral-400" title="{{ $entry->payload['notifiable'] ?? '' }}">{{ $entry->payload['notifiable'] ?? '—' }}</td>
                                <td class="py-2 pr-3">
                                    <span class="rounded-md border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">{{ $entry->subtype ?? '—' }}</span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $entry->duration !== null ? $fmt($entry->duration) : '—' }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-monitor::card>
    </div>
</div>
