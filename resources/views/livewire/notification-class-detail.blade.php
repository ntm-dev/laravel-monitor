@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();
    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
         x-data="{
             hoverIndex: null,
             setHoverIndex(i) { this.hoverIndex = i },
             clearHoverIndex() { this.hoverIndex = null },
         }">
        <x-monitor::card class="flex flex-col p-4">
            <x-monitor::metric :label="__('monitor::messages.nav.notifications')" :value="number_format($total)">
                @foreach ($channels as $channel)
                    <x-monitor::legend :label="$channel->label" :dot="$channel->dot" :value="number_format($channel->count)"/>
                @endforeach
            </x-monitor::metric>
            <div class="mt-5">
                <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]"
                    :series="[['label' => __('monitor::messages.nav.notifications'), 'dot' => 'bg-blue-500', 'data' => $volumeBuckets]]"/>
            </div>
            <x-monitor::chart-footer :since="$since" :until="$until"/>
        </x-monitor::card>
        <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- Individual sends --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="Icons::NOTIFICATIONS" class="h-4 w-4 text-blue-600 dark:text-purple-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalEntries) }} {{ trans_choice('monitor::messages.common.send_count', $totalEntries) }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_sends_recorded_in_period') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.date') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.source') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.notifiable') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.channel') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.duration') }}</th>
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                        @foreach ($entries as $entry)
                            @php($url = $entry->sourceUrl ?? route('monitor.notifications.sends.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($key), 'id' => $entry->id] + $range))
                            <tr class="rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset">
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                <td class="cursor-pointer max-w-[16rem] py-2 pr-3" onclick="window.location='{{ $url }}'">
                                    <x-monitor::exception-source-badge :type="$entry->sourceType" :label="$entry->sourceLabel" :url="$entry->sourceUrl"/>
                                </td>
                                <td class="max-w-[16rem] truncate py-2 pr-3 font-mono text-xs text-neutral-500 dark:text-neutral-400" title="{{ $entry->payload['notifiable'] ?? '' }}">{{ $entry->payload['notifiable'] ?? '—' }}</td>
                                <td class="py-2 pr-3">
                                    <span class="rounded-md bg-neutral-200 px-1.5 py-0.5 font-mono text-[11px] uppercase tracking-tight text-neutral-500 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-400 dark:shadow-neu-dark-inset">{{ $entry->subtype ?? '—' }}</span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $entry->duration !== null ? $fmt($entry->duration) : '—' }}</td>
                                <td class="py-2 pl-2 text-right">
                                    @if ($entry->sourceUrl)
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md text-neutral-300 dark:text-neutral-600" title="{{ __('monitor::messages.common.open_request') }}">
                                            <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <div class="mt-3 flex items-center justify-between shadow-[0_-1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_-1px_0_rgba(255,255,255,0.06)] pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>{{ __('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalEntries), 'total' => number_format($totalEntries)]) }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="previousPage" @disabled($page <= 1)
                                    class="rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2.5 py-1 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset disabled:opacity-40 disabled:shadow-none disabled:hover:shadow-none">{{ __('monitor::messages.common.prev') }}</button>
                            <span>{{ $page }} / {{ $lastPage }}</span>
                            <button type="button" wire:click="nextPage" @disabled($page >= $lastPage)
                                    class="rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2.5 py-1 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset disabled:opacity-40 disabled:shadow-none disabled:hover:shadow-none">{{ __('monitor::messages.common.next') }}</button>
                        </div>
                    </div>
                @endif
            @endif
        </x-monitor::card>
    </div>
</div>
