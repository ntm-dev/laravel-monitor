@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="Icons::MAIL" title="Mail">
        <x-slot:actions>
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search mail…"
                       class="h-8 w-56 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
            </div>
        </x-slot:actions>

        {{-- Overview charts --}}
        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
             x-data="{
                 hoverIndex: null,
                 setHoverIndex(i) { this.hoverIndex = i },
                 clearHoverIndex() { this.hoverIndex = null },
             }">
            <x-monitor::duration-chart-card label="Duration" :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>

            <x-monitor::card class="flex flex-col p-4">
                <x-monitor::metric label="Mails" :value="number_format($direct + $viaNotification)">
                    <x-monitor::legend label="Direct" dot="bg-blue-500" :value="number_format($direct)"/>
                    <x-monitor::legend label="Via notification" dot="bg-purple-500" :value="number_format($viaNotification)"/>
                </x-monitor::metric>
                <div class="mt-5">
                    <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]" :series="[
                        ['label' => 'Direct', 'dot' => 'bg-blue-500', 'data' => $directBuckets],
                        ['label' => 'Via notification', 'dot' => 'bg-purple-500', 'data' => $viaNotificationBuckets],
                    ]"/>
                </div>
                <x-monitor::chart-footer :since="$since" :until="$until"/>
            </x-monitor::card>
        </div>

        {{-- Grouped by mailable/notification class --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($groups->count()) }} Mail</h3>
        </div>

        @if ($groups->isEmpty())
            <x-monitor::empty-state label="Mail" message="No mail sent" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">Mail</th>
                            <th class="pb-2 text-right font-normal">Count</th>
                            <th class="pb-2 text-right font-normal">Avg</th>
                            <th class="pb-2 text-right font-normal">P95</th>
                            <th class="pb-2 text-right font-normal">Last sent</th>
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($groups as $group)
                            <tr class="group cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                onclick="window.location='{{ route('monitor.dashboard', ['tab' => 'mail', 'key' => $group->key] + $range) }}'">
                                <td class="max-w-[20rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $group->key }}">{{ class_basename($group->key) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($group->count) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($group->avg_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($group->p95_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $group->last_seen->diffForHumans(short: true) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
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
