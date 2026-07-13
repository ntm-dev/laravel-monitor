<div wire:poll.{{ $refresh }}s>
    @if (! $exists)
        <x-monitor::card class="p-10 text-center">
            <p class="text-lg font-semibold text-neutral-900">Exception not found</p>
            <p class="mt-1 text-sm text-neutral-500">This exception has no occurrences {{ $periodPhrase }}.</p>
            <a href="{{ route('monitor.dashboard', ['tab' => 'exceptions'] + $range) }}" class="mt-4 inline-block text-sm text-blue-600 hover:underline">← Back to exceptions</a>
        </x-monitor::card>
    @else
        {{-- The exception message renders in the page header, beside the range picker. --}}
        {{-- Summary: metadata + timeline --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-monitor::card class="p-4">
                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500">Summary</p>
                <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3">
                    @foreach ($summary as [$label, $value])
                        <div class="flex max-w-full items-baseline gap-2 h-6 text-sm font-mono">
                            <div class="uppercase text-neutral-500 dark:text-neutral-400 shrink-0">{{ $label }}</div>
                            <div class="min-w-6 grow h-3 border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <div class="truncate text-neutral-900 dark:text-white">
                                <span data-tippy-content="{{ $value }}">
                                    {{ $value }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </dl>
            </x-monitor::card>

            <div x-data="{
                     hoverIndex: null,
                     setHoverIndex(i) { this.hoverIndex = i },
                     clearHoverIndex() { this.hoverIndex = null },
                 }">
                <x-monitor::exceptions-chart-card
                    :count="$occurrencesCount" :handled="$handledCount" :unhandled="$unhandledCount"
                    :handled-buckets="$handledBuckets" :unhandled-buckets="$unhandledBuckets"
                    :since="$since" :until="$until" height="h-40" label="Occurrences" class="h-full"/>
            </div>
        </div>

        {{-- Stack trace --}}
        <div class="mt-6">
            <div class="flex flex-col rounded-lg border border-neutral-200 bg-white shadow-md shadow-black/5 dark:border-white/5 dark:bg-white/2 dark:shadow-black/20 overflow-hidden">
                <div class="flex flex-col gap-3 p-4 md:p-5"
                     x-data="{ copied: false, copy() {
                         navigator.clipboard.writeText(@js($markdown)).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1600); });
                     } }">
                    <div class="flex justify-between gap-2 max-md:flex-col md:items-center">
                        <x-monitor::status-badge :handled="$handled"/>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="copy()"
                                    class="group flex h-6 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 bg-white/50 px-1.5 text-xs leading-none text-neutral-600 hover:border-blue-500 hover:bg-white hover:text-neutral-900 active:translate-y-px active:bg-neutral-100">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 group-hover:text-blue-600"/>
                                <span x-text="copied ? 'Copied!' : 'Copy as Markdown'"></span>
                            </button>
                            <div
                                class="flex h-6 w-fit shrink-0 items-center divide-x divide-neutral-200 rounded-sm border border-neutral-200 bg-white font-mono text-xs">
                                <div class="flex items-center gap-2 px-2 py-0.5">
                                    <div class="uppercase text-neutral-500">Laravel</div>
                                    <div>{{ $laravelVersion ?? '—' }}</div>
                                </div>
                                <div class="flex items-center gap-2 px-2 py-0.5">
                                    <div class="uppercase text-neutral-500">PHP</div>
                                    <div>{{ $phpVersion ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-1 min-w-0 flex-1 break-all text-2xl/none font-semibold {{ $handled ? 'text-neutral-900' : 'text-rose-600' }}" title="{{ $class }}">{{ $class }}</div>
                    @if (filled($message))
                        <p class="break-words text-sm text-neutral-600">{{ $message }}</p>
                    @endif
                </div>
            @if (! empty($frameGroups))
                <x-monitor::stack-trace :groups="$frameGroups"/>
            @else
                <x-monitor::card class="p-8 text-center text-sm text-neutral-400">No stack trace was captured for this exception.</x-monitor::card>
            @endif
            </div>
        </div>

        {{-- Occurrences --}}
        <div class="mt-6">
            <div class="flex items-center gap-2 px-1 pb-3">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOCK" class="h-4 w-4 text-blue-600"/>
                <h3 class="font-semibold text-neutral-900">{{ number_format($occurrences->count()) }} {{ $occurrences->count() === 1 ? 'Occurrence' : 'Occurrences' }}</h3>
                @if ($occurrencesCount > $occurrences->count())
                    <span class="font-mono text-xs text-neutral-400">(showing latest {{ $occurrences->count() }} of {{ number_format($occurrencesCount) }})</span>
                @endif
            </div>
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 text-left font-mono text-xs uppercase tracking-tight text-neutral-500">
                                <th class="pb-2 font-normal">Date</th>
                                <th class="pb-2 font-normal">Source</th>
                                <th class="pb-2 font-normal">Message</th>
                                <th class="pb-2 font-normal">User</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            @foreach ($occurrences as $occurrence)
                                <tr class="hover:bg-neutral-50">
                                    <td class="whitespace-nowrap py-2 pr-3 font-mono text-xs text-neutral-700">{{ $occurrence->date }} <span class="text-neutral-300">{{ $tz }}</span></td>
                                    <td class="py-2 pr-3 font-mono text-xs text-neutral-500">{{ $occurrence->server ?? '—' }}</td>
                                    <td class="max-w-[22rem] truncate py-2 pr-3 text-xs text-neutral-600" title="{{ $occurrence->message }}">{{ $occurrence->message ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-xs text-neutral-600">{{ $occurrence->user ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-monitor::card>
        </div>
    @endif
</div>
