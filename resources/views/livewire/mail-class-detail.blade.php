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
            <x-monitor::metric :label="__('monitor::messages.nav.mail')" :value="number_format($total)"/>
            <div class="mt-5">
                <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]"
                    :series="[['label' => __('monitor::messages.nav.mail'), 'dot' => 'bg-blue-500', 'data' => $volumeBuckets]]"/>
            </div>
            <x-monitor::chart-footer :since="$since" :until="$until"/>
        </x-monitor::card>
        <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- Individual sends --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="Icons::MAIL" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalEntries) }} {{ trans_choice('monitor::messages.common.send_count', $totalEntries) }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_sends_recorded_in_period') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="w-px pb-2 font-normal">{{ __('monitor::messages.common.date') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.source') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.mailer') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.subject') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.recipients') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.duration') }}</th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($entries as $entry)
                                @php($url = $entry->sourceUrl ?? route('monitor.mail.sends.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($key), 'id' => $entry->id] + $range))
                                <tr class="group hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="whitespace-nowrap py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                    <td class="max-w-[16rem] cursor-pointer py-2 pr-3" onclick="window.location='{{ $url }}'">
                                        <x-monitor::exception-source-badge :type="$entry->sourceType" :label="$entry->sourceLabel" :url="$entry->sourceUrl"/>
                                    </td>
                                    <td class="py-2 pr-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $entry->payload['mailer'] ?? '—' }}</td>
                                    <td class="max-w-[16rem] truncate py-2 pr-3 text-xs text-neutral-700 dark:text-neutral-200" title="{{ $entry->payload['subject'] ?? '' }}">{{ $entry->payload['subject'] ?? '(no subject)' }}</td>
                                    <td class="py-2 pr-3">
                                        {{-- Icon + total recipients, with a dark dotted-line breakdown tooltip on hover — mirrors Nightwatch's own Mail list Recipients column. --}}
                                        <div class="group/recipients relative inline-flex cursor-default items-center gap-3">
                                            <span class="inline-flex items-center gap-1 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                                                <x-monitor::icon :path="Icons::USER" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500"/>
                                                {{ number_format(($entry->payload['to_count'] ?? 0) + ($entry->payload['cc_count'] ?? 0) + ($entry->payload['bcc_count'] ?? 0)) }}
                                            </span>
                                            <span class="inline-flex items-center gap-1 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                                                <x-monitor::icon :path="Icons::PAPER_CLIP" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500"/>
                                                {{ number_format($entry->payload['attachments'] ?? 0) }}
                                            </span>
                                            <div class="pointer-events-none invisible absolute bottom-full left-0 z-10 mb-1.5 w-36 rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 opacity-0 shadow-lg transition-opacity group-hover/recipients:visible group-hover/recipients:opacity-100">
                                                <dl class="flex flex-col gap-0.5">
                                                    @foreach ([
                                                        'to' => $entry->payload['to_count'] ?? 0,
                                                        'cc' => $entry->payload['cc_count'] ?? 0,
                                                        'bcc' => $entry->payload['bcc_count'] ?? 0,
                                                        'attachments' => $entry->payload['attachments'] ?? 0,
                                                    ] as $label => $count)
                                                        <div class="flex items-baseline gap-2">
                                                            <dt class="shrink-0 font-mono text-[10px] uppercase tracking-tight text-neutral-400">{{ __('monitor::messages.common.'.$label) }}</dt>
                                                            <div class="relative -bottom-px min-w-2 grow border-b border-dotted border-white/20"></div>
                                                            <dd class="shrink-0 font-mono text-[11px] text-white">{{ number_format($count) }}</dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            </div>
                                        </div>
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
                </div>

                @if ($lastPage > 1)
                    <div class="mt-3 flex items-center justify-between border-t border-neutral-100 dark:border-neutral-800 pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>{{ __('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalEntries), 'total' => number_format($totalEntries)]) }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="previousPage" @disabled($page <= 1)
                                    class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">{{ __('monitor::messages.common.prev') }}</button>
                            <span>{{ $page }} / {{ $lastPage }}</span>
                            <button type="button" wire:click="nextPage" @disabled($page >= $lastPage)
                                    class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">{{ __('monitor::messages.common.next') }}</button>
                        </div>
                    </div>
                @endif
            @endif
        </x-monitor::card>
    </div>
</div>
