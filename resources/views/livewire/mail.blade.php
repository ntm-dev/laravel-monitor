@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);

    $columns = [
        'key' => ['label' => __('monitor::messages.nav.mail'), 'align' => 'left'],
        'count' => ['label' => __('monitor::messages.common.count'), 'align' => 'right'],
        'avg_duration' => ['label' => __('monitor::messages.common.avg'), 'align' => 'right'],
        'p95_duration' => ['label' => __('monitor::messages.common.p95'), 'align' => 'right'],
        'last_seen' => ['label' => __('monitor::messages.common.last_sent'), 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        <x-slot:actions>
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search_mail') }}"
                       class="h-8 w-56 rounded-xl bg-neutral-200 dark:bg-neutral-800 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-neu-inset dark:shadow-neu-dark-inset">
            </div>
        </x-slot:actions>

        {{-- Overview charts --}}
        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
             x-data="{
                 hoverIndex: null,
                 setHoverIndex(i) { this.hoverIndex = i },
                 clearHoverIndex() { this.hoverIndex = null },
             }">
            <x-monitor::card class="flex flex-col p-4">
                <x-monitor::metric :label="__('monitor::messages.common.mails')" :value="number_format($direct + $viaNotification)">
                    <x-monitor::legend :label="__('monitor::messages.common.direct')" dot="bg-blue-500" :value="number_format($direct)"/>
                    <x-monitor::legend :label="__('monitor::messages.common.via_notification')" dot="bg-purple-500" :value="number_format($viaNotification)"/>
                </x-monitor::metric>
                <div class="mt-5">
                    <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]" :series="[
                        ['label' => __('monitor::messages.common.direct'), 'dot' => 'bg-blue-500', 'data' => $directBuckets],
                        ['label' => __('monitor::messages.common.via_notification'), 'dot' => 'bg-purple-500', 'data' => $viaNotificationBuckets],
                    ]"/>
                </div>
                <x-monitor::chart-footer :since="$since" :until="$until"/>
            </x-monitor::card>

            <x-monitor::duration-chart-card :label="__('monitor::messages.common.duration')" :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
        </div>

        {{-- Grouped by mailable/notification class --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalGroups) }} {{ __('monitor::messages.nav.mail') }}</h3>
        </div>

        @if ($groups->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.mail')" :message="__('monitor::messages.common.no_mail_sent')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            @foreach ($columns as $field => $column)
                                <th class="cursor-pointer select-none pb-2 font-normal {{ $column['align'] === 'right' ? 'text-right' : 'text-left' }}"
                                    wire:click="sort('{{ $field }}')">
                                    <span class="inline-flex items-center gap-1 {{ $column['align'] === 'right' ? 'flex-row' : '' }}">
                                        {{ $column['label'] }}
                                        <div class=" -right-3 flex flex-col gap-[2px]">
                                            <div class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px] border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden {{ $sortBy === $field && $sortDirection === 'asc' ? 'border-b-blue-500' : '' }}">
                                            </div>
                                            <div class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px] border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden {{ $sortBy === $field && $sortDirection !== 'asc' ? 'border-b-blue-500' : '' }} rotate-180">
                                            </div>
                                        </div>
                                    </span>
                                </th>
                            @endforeach
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                        @foreach ($groups as $group)
                            <tr class="group cursor-pointer rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset"
                                onclick="window.location='{{ route('monitor.mail.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($group->key)] + $range) }}'">
                                <td class="max-w-[20rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $group->key }}">{{ $group->key }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($group->count) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($group->avg_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($group->p95_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $group->last_seen->diffForHumans(short: true) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg text-neutral-300 dark:text-neutral-600 group-hover:bg-neutral-200 dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-neu-sm dark:group-hover:shadow-neu-dark-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <div class="mt-3 flex items-center justify-between shadow-[0_-1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_-1px_0_rgba(255,255,255,0.06)] pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>{{ __('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalGroups), 'total' => number_format($totalGroups)]) }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="previousPage" @disabled($page <= 1)
                                    class="rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2.5 py-1 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset disabled:opacity-40 disabled:shadow-none disabled:hover:shadow-none">{{ __('monitor::messages.common.prev') }}</button>
                            <span>{{ $page }} / {{ $lastPage }}</span>
                            <button type="button" wire:click="nextPage" @disabled($page >= $lastPage)
                                    class="rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2.5 py-1 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset disabled:opacity-40 disabled:shadow-none disabled:hover:shadow-none">{{ __('monitor::messages.common.next') }}</button>
                        </div>
                    </div>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
