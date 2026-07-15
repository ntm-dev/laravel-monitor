@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();

    $typeBadgeClass = $isWrite
        ? 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400'
        : 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400';
    $typeLabel = $isWrite ? 'Write' : 'Read';
@endphp
<div wire:poll.{{ $refresh }}s>
    {{-- Full SQL — the page header shows a wrapped version of this same
         text; this card renders it syntax-highlighted. --}}
    <x-monitor::card class="p-4">
        <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">SQL</p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-neutral-50 dark:bg-neutral-800/50 p-3 font-mono text-xs leading-relaxed text-neutral-800 dark:text-neutral-200"><code data-line-code data-lang="sql">{{ $key }}</code></pre>
    </x-monitor::card>

    {{-- Info --}}
    <x-monitor::card class="mt-1.5 p-4">
        <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Info</p>
        <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-3">
            <div>
                <dt class="font-mono text-[11px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">Type</dt>
                <dd class="mt-1">
                    <span class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-tight {{ $typeBadgeClass }}">{{ $typeLabel }}</span>
                </dd>
            </div>
            <div>
                <dt class="font-mono text-[11px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">First seen</dt>
                <dd class="mt-1 font-mono text-xs text-neutral-700 dark:text-neutral-200">
                    {{ $firstSeen ? Format::datetime($firstSeen).' '.$tz : '—' }}
                </dd>
            </div>
            <div>
                <dt class="font-mono text-[11px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">Connections</dt>
                <dd class="mt-1 flex flex-wrap gap-1">
                    @forelse ($connections as $conn)
                        <span class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 px-1.5 py-0.5 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ $conn }}</span>
                    @empty
                        <span class="font-mono text-xs text-neutral-400 dark:text-neutral-500">—</span>
                    @endforelse
                </dd>
            </div>
        </dl>
    </x-monitor::card>

    <div class="mt-1.5 grid grid-cols-1 gap-1.5 lg:grid-cols-2"
         x-data="{
             hoverIndex: null,
             setHoverIndex(i) { this.hoverIndex = i },
             clearHoverIndex() { this.hoverIndex = null },
         }">
        <x-monitor::card class="flex flex-col p-4">
            <x-monitor::metric label="Calls" :value="number_format($calls)"/>
            <div class="mt-5">
                <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]"
                    :series="[['label' => 'Calls', 'dot' => 'bg-blue-500', 'data' => $callBuckets]]"/>
            </div>
            <x-monitor::chart-footer :since="$since" :until="$until"/>
        </x-monitor::card>
        <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- Individual calls --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="Icons::QUERIES" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($entries->count()) }} {{ $entries->count() === 1 ? 'Call' : 'Calls' }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">No calls recorded in this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="pb-2 font-normal">Date</th>
                                <th class="pb-2 font-normal">Source</th>
                                <th class="pb-2 font-normal">Connection</th>
                                <th class="pb-2 font-normal">Location</th>
                                <th class="pb-2 text-right font-normal">Duration</th>
                                <th class="w-8 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($entries as $entry)
                                @php($requestUrl = ($entry->request_id ?? null) ? route('monitor.requests.show', $entry->request_id) : null)
                                @php($requestLabel = $requestUrl ? ($requestLabels[$entry->request_id] ?? null) : null)
                                @php($commandName = $entry->payload['command'] ?? null)
                                @php($location = $entry->payload['location'] ?? null)
                                @php($connection = $entry->payload['connection'] ?? null)
                                <tr class="{{ $requestUrl ? 'cursor-pointer' : '' }} hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                    @if ($requestUrl) onclick="window.location='{{ $requestUrl }}'" @endif>
                                    <td class="py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                    <td class="max-w-[16rem] py-2 pr-3">
                                        @if ($requestUrl)
                                            <span class="flex items-center gap-1.5">
                                                <span class="shrink-0 rounded-md border border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-tight text-blue-600 dark:text-blue-400">Request</span>
                                                <span class="truncate font-mono text-xs text-neutral-600 dark:text-neutral-300" title="{{ $requestLabel }}">{{ $requestLabel ?? '—' }}</span>
                                            </span>
                                        @else
                                            <span class="flex items-center gap-1.5">
                                                <span class="shrink-0 rounded-md border border-neutral-200 dark:border-neutral-700 bg-neutral-100 dark:bg-neutral-800 px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Command</span>
                                                <span class="truncate font-mono text-xs text-neutral-600 dark:text-neutral-300" title="{{ $commandName }}">{{ $commandName ?? '—' }}</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3">
                                        <span class="inline-flex items-center gap-1.5 font-mono text-xs text-neutral-600 dark:text-neutral-300">
                                            {{ $connection ?? '—' }}
                                            @if ($connection)
                                                <span class="rounded border px-1 font-mono text-[9px] font-medium uppercase leading-tight {{ $typeBadgeClass }}">{{ $typeLabel }}</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="group max-w-[18rem] py-2 pr-3" x-data="{ copied: false }">
                                        <span class="flex items-center gap-1.5">
                                            <span class="truncate font-mono text-xs text-neutral-500 dark:text-neutral-400" title="{{ $location }}">{{ $location ?? '—' }}</span>
                                            @if ($location)
                                                <button type="button" @click.stop="navigator.clipboard.writeText(@js($location)); copied = true; setTimeout(() => copied = false, 1200)"
                                                        class="shrink-0 text-neutral-400 opacity-0 hover:text-neutral-700 group-hover:opacity-100 dark:text-neutral-500 dark:hover:text-neutral-200">
                                                    <x-monitor::icon :path="Icons::COPY" :stroke="1.8" class="h-3 w-3" x-show="! copied"/>
                                                    <x-monitor::icon :path="Icons::CHECK" :stroke="2" class="h-3 w-3 text-emerald-500" x-show="copied" x-cloak/>
                                                </button>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="py-2 text-right font-mono text-xs {{ $entry->subtype === 'slow' ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($entry->duration) }}</td>
                                    <td class="py-2 pl-2 text-right">
                                        @if ($requestUrl)
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md text-neutral-300 dark:text-neutral-600" title="Open request">
                                                <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-monitor::card>
    </div>
</div>
