<div wire:poll.{{ $refresh }}s>
    @if (! $exists)
        <x-monitor::card class="p-10 text-center">
            <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.issue.exception_not_found') }}</p>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.exception.no_occurrences_in_period', ['period' => $periodPhrase]) }}</p>
            <a href="{{ route('monitor.dashboard', ['tab' => 'exceptions'] + $range) }}" class="mt-4 inline-block text-sm text-blue-600 dark:text-blue-400 hover:underline">{{ __('monitor::messages.exception.back_to_exceptions') }}</a>
        </x-monitor::card>
    @else
        {{-- The exception message renders in the page header, beside the range picker. --}}
        {{-- Summary: metadata + timeline --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-monitor::card class="p-4">
                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.issue.summary') }}</p>
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
                    :since="$since" :until="$until" height="h-40" :label="__('monitor::messages.common.occurrences')" class="h-full"/>
            </div>
        </div>

        {{-- Stack trace --}}
        <div class="mt-6">
            {{-- dark:bg-white/5, not /2: this app loads Tailwind via the
                 cdn.tailwindcss.com script, whose JIT only generates
                 color-opacity modifiers on the default scale
                 (0/5/10/20/25/...); `/2` silently compiled to nothing,
                 leaving this card stuck on its light-mode `bg-white`
                 regardless of theme. --}}
            <div class="flex flex-col rounded-lg border border-neutral-200 bg-white shadow-md shadow-black/5 dark:border-white/5 dark:bg-white/5 dark:shadow-black/20 overflow-hidden">
                <div class="flex flex-col gap-3 p-4 md:p-5"
                     x-data="{ copied: false, copy() {
                         navigator.clipboard.writeText(@js($markdown)).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1600); });
                     } }">
                    <div class="flex justify-between gap-2 max-md:flex-col md:items-center">
                        <x-monitor::status-badge :handled="$handled"/>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="copy()"
                                    class="group flex h-6 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white/50 dark:bg-neutral-900/50 px-1.5 text-xs leading-none text-neutral-600 dark:text-neutral-300 hover:border-blue-500 hover:bg-white dark:hover:bg-neutral-900 hover:text-neutral-900 dark:hover:text-neutral-100 active:translate-y-px active:bg-neutral-100 dark:active:bg-neutral-800">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500 group-hover:text-blue-600 dark:group-hover:text-blue-400"/>
                                <span x-text="copied ? '{{ __('monitor::messages.common.copied') }}' : '{{ __('monitor::messages.common.copy_as_markdown') }}'"></span>
                            </button>
                            <div
                                class="flex h-6 w-fit shrink-0 items-center divide-x divide-neutral-200 dark:divide-neutral-700 rounded-sm border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 font-mono text-xs">
                                <div class="flex items-center gap-2 px-2 py-0.5">
                                    <div class="uppercase text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.laravel') }}</div>
                                    <div>{{ $laravelVersion ?? '—' }}</div>
                                </div>
                                <div class="flex items-center gap-2 px-2 py-0.5">
                                    <div class="uppercase text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.php') }}</div>
                                    <div>{{ $phpVersion ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-1 min-w-0 flex-1 break-all text-2xl/none font-semibold {{ $handled ? 'text-neutral-900 dark:text-neutral-100' : 'text-rose-600 dark:text-rose-400' }}" data-tooltip="{{ $class }}">{{ $class }}</div>
                    @if (filled($message))
                        <p class="break-words text-sm text-neutral-600 dark:text-neutral-300">{{ $message }}</p>
                    @endif
                </div>
            @if (! empty($frameGroups))
                <x-monitor::stack-trace :groups="$frameGroups"/>
            @else
                <x-monitor::card class="p-8 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.issue.no_stack_trace') }}</x-monitor::card>
            @endif
            </div>
        </div>

        {{-- Occurrences --}}
        <div class="mt-6">
            <div class="flex items-center gap-2 px-1 pb-3">
                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOCK" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($occurrences->count()) }} {{ trans_choice('monitor::messages.issue.occurrence_count', $occurrences->count()) }}</h3>
                @if ($occurrencesCount > $occurrences->count())
                    <span class="font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.showing_latest_of', ['count' => $occurrences->count(), 'total' => number_format($occurrencesCount)]) }}</span>
                @endif
            </div>
            <x-monitor::card class="p-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.date') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.source') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.message') }}</th>
                                <th class="pb-2 font-normal">{{ __('monitor::messages.common.user') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($occurrences as $occurrence)
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="whitespace-nowrap py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $occurrence->date }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                    <td class="max-w-[14rem] py-2 pr-3">
                                        <x-monitor::exception-source-badge :type="$occurrence->sourceType" :label="$occurrence->sourceLabel" :url="$occurrence->sourceUrl"/>
                                    </td>
                                    <td class="max-w-[22rem] truncate py-2 pr-3 text-xs text-neutral-600 dark:text-neutral-300" data-tooltip="{{ $occurrence->message }}">{{ $occurrence->message ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-xs text-neutral-600 dark:text-neutral-300">{{ $occurrence->user }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-monitor::card>
        </div>
    @endif
</div>
