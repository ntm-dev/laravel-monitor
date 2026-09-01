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
            <x-monitor::metric :label="__('monitor::messages.common.calls')" :value="number_format($calls)"/>
            <div class="mt-5">
                <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]"
                    :series="[['label' => __('monitor::messages.common.calls'), 'dot' => 'bg-blue-500', 'data' => $callBuckets]]"/>
            </div>
            <x-monitor::chart-footer :since="$since" :until="$until"/>
        </x-monitor::card>
        <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- Info: metrics list on the left half, the full SQL as a nested card
         on the right half. The page header already shows a wrapped copy of
         the SQL for quick reference; this is the canonical full text,
         wrapping instead of scrolling horizontally, capped in height with
         its own vertical scroll for pathologically long queries. --}}
    <x-monitor::card class="mt-1.5 flex flex-col gap-6 p-4 md:flex-row">
        <div class="md:w-1/2">
            <h3 class="pb-4 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.info') }}</h3>
            <dl class="flex flex-col gap-3">
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.total_time') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $fmt($totalTime) }}</dd>
                </div>
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.avg_time') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $fmt($duration->avg) }}</dd>
                </div>
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.p95') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $fmt($duration->p95) }}</dd>
                </div>
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.calls') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ number_format($calls) }}</dd>
                </div>
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.connection') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="flex flex-wrap justify-end gap-1">
                        @forelse ($connections as $conn)
                            <span class="inline-flex items-center gap-1 rounded-md border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 px-1.5 py-0.5 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">
                                {{ $conn['name'] }}
                                @if ($conn['type'])
                                    <span class="rounded border px-1 font-mono text-[9px] font-medium uppercase leading-tight {{ Format::CONNECTION_TYPE_BADGES[$conn['type']] }}">{{ $conn['type'] }}</span>
                                @endif
                            </span>
                        @empty
                            <span class="font-mono text-xs text-neutral-400 dark:text-neutral-500">—</span>
                        @endforelse
                    </dd>
                </div>
                <div class="flex max-w-full items-baseline gap-2">
                    <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.first_seen') }}</dt>
                    <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                    <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $firstSeen ? Format::datetime($firstSeen).' '.$tz : '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900 md:w-1/2"
             x-data="{
                 sql: @js($sql),
                 sqlCopied: false,
                 sqlHighlighted() {
                     if (! this.sql) return '';
                     {{-- Same 'mysql' dialect choice as Requests\Timeline's sqlHighlighted() —
                          keep this in sync if that reasoning ever changes. --}}
                     const formatted = window.sqlFormatter ? window.sqlFormatter.format(this.sql, { language: 'mysql' }) : this.sql;
                     return window.hljs ? window.hljs.highlight(formatted, { language: 'sql', ignoreIllegals: true }).value : formatted;
                 },
                 copySql() {
                     if (! this.sql) return;
                     navigator.clipboard.writeText(this.sql);
                     this.sqlCopied = true;
                     setTimeout(() => this.sqlCopied = false, 1500);
                 },
             }">
            <div class="max-h-64 overflow-auto p-4">
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="font-mono text-[10px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.sql') }}</span>
                    <button type="button" @click="copySql()" data-tooltip="{{ __('monitor::messages.common.copy') }}"
                            class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                        <x-monitor::icon :path="Icons::COPY" class="h-3.5 w-3.5" x-show="! sqlCopied"/>
                        <x-monitor::icon :path="Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="sqlCopied" x-cloak
                            x-transition:enter="transition-[clip-path] ease-out duration-1000" x-transition:enter-start="[clip-path:inset(0_100%_0_0)]" x-transition:enter-end="[clip-path:inset(0_0_0_0)]"/>
                    </button>
                </div>
                <pre class="whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-neutral-800 dark:text-neutral-200"><code data-line-code data-lang="sql" x-html="sqlHighlighted()"></code></pre>
            </div>
        </div>
    </x-monitor::card>

    {{-- Individual calls --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="Icons::QUERIES" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalEntries) }} {{ trans_choice('monitor::messages.common.call_count', $totalEntries) }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_calls_recorded_in_period') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.date') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.source') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.connection') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.location') }}</th>
                                <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.duration') }}</th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($entries as $entry)
                                {{-- request_id is a generic correlation id (request/job/command/scheduled task) —
                                     sourceType/sourceLabel/sourceUrl (set in QueryDetail::data()) resolve it to the
                                     right detail page instead of assuming every call came from an HTTP request. --}}
                                @php($commandName = $entry->payload['command'] ?? null)
                                @php($location = $entry->payload['location'] ?? null)
                                @php($connection = $entry->payload['connection'] ?? null)
                                @php($connectionType = $entry->payload['connection_type'] ?? null)
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                    <td class="{{ $entry->sourceUrl ? 'cursor-pointer' : '' }} max-w-[16rem] py-2 pr-3" @if ($entry->sourceUrl) onclick="window.location='{{ $entry->sourceUrl }}'" @endif>
                                        @if ($entry->sourceType)
                                            <x-monitor::exception-source-badge :type="$entry->sourceType" :label="$entry->sourceLabel" :url="$entry->sourceUrl"/>
                                        @else
                                            <span class="flex items-center gap-1.5">
                                                <span class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-neutral-100 dark:bg-neutral-800 px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.exception.source_command') }}</span>
                                                <span class="truncate font-mono text-xs text-neutral-600 dark:text-neutral-300" data-tooltip="{{ $commandName }}">{{ $commandName ?? '—' }}</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3">
                                        <span class="inline-flex items-center gap-1.5 font-mono text-xs text-neutral-600 dark:text-neutral-300">
                                            {{ $connection ?? '—' }}
                                            @if ($connectionType)
                                                <span class="rounded border px-1 font-mono text-[9px] font-medium uppercase leading-tight {{ Format::CONNECTION_TYPE_BADGES[$connectionType] }}">{{ $connectionType }}</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="group max-w-[18rem] py-2 pr-3" x-data="{ copied: false }">
                                        <span class="flex items-center gap-1.5">
                                            <span class="truncate font-mono text-xs text-neutral-500 dark:text-neutral-400" data-tooltip="{{ $location }}">{{ $location ?? '—' }}</span>
                                            @if ($location)
                                                <button type="button" @click.stop="navigator.clipboard.writeText(@js($location)); copied = true; setTimeout(() => copied = false, 1200)"
                                                        class="shrink-0 text-neutral-400 opacity-0 hover:text-neutral-700 group-hover:opacity-100 dark:text-neutral-500 dark:hover:text-neutral-200">
                                                    <x-monitor::icon :path="Icons::COPY" :stroke="1.8" class="h-3 w-3" x-show="! copied"/>
                                                    <x-monitor::icon :path="Icons::CHECK" :stroke="2" class="h-3 w-3 text-emerald-500" x-show="copied" x-cloak
                                                        x-transition:enter="transition-[clip-path] ease-out duration-1000" x-transition:enter-start="[clip-path:inset(0_100%_0_0)]" x-transition:enter-end="[clip-path:inset(0_0_0_0)]"/>
                                                </button>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="py-2 text-right font-mono text-xs {{ $entry->duration >= $slowThreshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($entry->duration) }}</td>
                                    <td class="py-2 pl-2 text-right">
                                        @if ($entry->sourceUrl)
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md text-neutral-300 dark:text-neutral-600" data-tooltip="{{ __('monitor::messages.common.open_request') }}">
                                                <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-100 dark:divide-neutral-800">
                            <x-monitor::table-skeleton :columns="6" :rows="count($entries)"/>
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
