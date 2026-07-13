@php
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\Format;
@endphp
<div wire:poll.{{ $refresh }}s>
    @if (! $exists)
        <x-monitor::card class="p-10 text-center">
            <p class="text-lg font-semibold text-neutral-900">Exception not found</p>
            <p class="mt-1 text-sm text-neutral-500">This exception has no occurrences {{ $periodPhrase }}.</p>
            <a href="{{ route('monitor.dashboard', ['tab' => 'exceptions'] + $range) }}" class="mt-4 inline-block text-sm text-blue-600 hover:underline">← Back to exceptions</a>
        </x-monitor::card>
    @else
        {{-- Exception header --}}
        <div class="flex flex-wrap items-start justify-between gap-3"
             x-data="{ copied: false, copy() {
                 navigator.clipboard.writeText(@js($markdown)).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1600); });
             } }">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-monitor::status-badge :handled="$handled"/>
                    @if ($laravelVersion)
                        <span class="rounded border border-neutral-200 bg-white px-1.5 py-0.5 font-mono text-[10px] text-neutral-500">Laravel {{ $laravelVersion }}</span>
                    @endif
                    @if ($phpVersion)
                        <span class="rounded border border-neutral-200 bg-white px-1.5 py-0.5 font-mono text-[10px] text-neutral-500">PHP {{ $phpVersion }}</span>
                    @endif
                </div>
                <h2 class="mt-2 break-all font-mono text-lg font-semibold {{ $handled ? 'text-neutral-900' : 'text-rose-600' }}" title="{{ $class }}">{{ $class }}</h2>
                @if (filled($message))
                    <p class="mt-1 break-words text-sm text-neutral-600">{{ $message }}</p>
                @endif
                @if (filled($file))
                    <p class="mt-1 font-mono text-xs text-neutral-400">{{ $file }}:{{ $line }}</p>
                @endif
            </div>
            <button type="button" @click="copy()"
                    class="flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 bg-white px-3 text-xs font-medium text-neutral-600 shadow-sm hover:bg-neutral-50">
                <x-monitor::icon :path="Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5"/>
                <span x-text="copied ? 'Copied!' : 'Copy as Markdown'"></span>
            </button>
        </div>

        {{-- Summary: metadata + timeline --}}
        <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-monitor::card class="p-4">
                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500">Summary</p>
                <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3">
                    @foreach ($summary as [$label, $value])
                        <div class="flex max-w-full items-baseline gap-2 h-10 text-sm font-mono">
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
            <div class="flex items-center gap-2 px-1 pb-3">
                <x-monitor::icon :path="Icons::EXCEPTIONS" class="h-4 w-4 text-rose-500"/>
                <h3 class="font-semibold text-neutral-900">Stack Trace</h3>
            </div>
            @if (! empty($frameGroups))
                <x-monitor::stack-trace :groups="$frameGroups"/>
            @else
                <x-monitor::card class="p-8 text-center text-sm text-neutral-400">No stack trace was captured for this exception.</x-monitor::card>
            @endif
        </div>

        {{-- Occurrences --}}
        <div class="mt-6">
            <div class="flex items-center gap-2 px-1 pb-3">
                <x-monitor::icon :path="Icons::CLOCK" class="h-4 w-4 text-blue-600"/>
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
                                    <td class="whitespace-nowrap py-2 pr-3 font-mono text-xs text-neutral-700">{{ Format::datetime($occurrence->created_at) }} <span class="text-neutral-300">{{ $tz }}</span></td>
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
