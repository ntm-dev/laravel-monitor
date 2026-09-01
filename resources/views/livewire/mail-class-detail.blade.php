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
                        <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($entries as $entry)
                                @php($sendUrl = route('monitor.mail.sends.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($key), 'id' => $entry->id] + $range))
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="whitespace-nowrap py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                    <td class="{{ $entry->sourceUrl ? 'cursor-pointer' : '' }} max-w-[16rem] py-2 pr-3" @if ($entry->sourceUrl) onclick="window.location='{{ $entry->sourceUrl }}'" @endif>
                                        <x-monitor::exception-source-badge :type="$entry->sourceType" :label="$entry->sourceLabel" :url="$entry->sourceUrl"/>
                                    </td>
                                    <td class="py-2 pr-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $entry->payload['mailer'] ?? '—' }}</td>
                                    <td class="max-w-[16rem] truncate py-2 pr-3 text-xs text-neutral-700 dark:text-neutral-200" data-tooltip="{{ $entry->payload['subject'] ?? '' }}">{{ $entry->payload['subject'] ?? '(no subject)' }}</td>
                                    <td class="py-2 pr-3">
                                        {{-- Icon + total recipients, with a dark dotted-line breakdown tooltip on hover.
                                             Positioned via Alpine as position:fixed (coordinates from getBoundingClientRect on hover) rather than CSS group-hover +
                                             position:absolute: the ancestor .overflow-x-auto wrapper (needed for horizontal scroll on narrow screens) forces
                                             overflow-y:auto too, which clipped an absolutely-positioned tooltip popping upward for rows near the top of the table.
                                             No ancestor here has a transform/filter, so position:fixed escapes that clip without needing x-teleport. --}}
                                        <div class="inline-flex cursor-default items-center gap-3"
                                             x-data="{ open: false, style: '' }"
                                             @mouseenter="const r = $el.getBoundingClientRect(); style = `left:${r.left}px; top:${r.top - 6}px;`; open = true"
                                             @mouseleave="open = false">
                                            <span class="inline-flex items-center gap-1 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                                                <x-monitor::icon :path="Icons::USER" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500"/>
                                                {{ number_format(($entry->payload['to_count'] ?? 0) + ($entry->payload['cc_count'] ?? 0) + ($entry->payload['bcc_count'] ?? 0)) }}
                                            </span>
                                            <span class="inline-flex items-center gap-1 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                                                <x-monitor::icon :path="Icons::PAPER_CLIP" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500"/>
                                                {{ number_format($entry->payload['attachments'] ?? 0) }}
                                            </span>
                                            <div x-show="open" x-cloak x-transition.opacity.duration.100ms :style="style"
                                                 class="pointer-events-none fixed z-50 w-36 -translate-y-full rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 shadow-lg">
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
                                    <td class="cursor-pointer py-2 pl-2 text-right" onclick="window.location='{{ $sendUrl }}'">
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 hover:border-neutral-200 dark:hover:border-neutral-700 hover:bg-white dark:hover:bg-neutral-900 hover:text-neutral-600 dark:hover:text-neutral-300 hover:shadow-sm"
                                              data-tooltip="{{ __('monitor::messages.common.open_mail') }}">
                                            <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-100 dark:divide-neutral-800">
                            <x-monitor::table-skeleton :columns="7" :rows="count($entries)"/>
                        </tbody>
                    </table>
                </div>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalEntries), 'total' => number_format($totalEntries)])"/>
                @endif
            @endif
        </x-monitor::card>
    </div>
</div>
