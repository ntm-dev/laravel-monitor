<div wire:poll.{{ $refresh }}s>

    <select wire:model.live="level"
        class="h-8 rounded-xl bg-neutral-200 dark:bg-neutral-800 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-neu-inset dark:shadow-neu-dark-inset">
        <option value="">{{ __('monitor::messages.common.all_levels') }}</option>
        @foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'] as $option)
            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
        @endforeach
    </select>

    @if ($logs->isEmpty())
        <x-monitor::empty-state :label="__('monitor::messages.nav.logs')" :message="__('monitor::messages.common.no_log_entries')" :period-phrase="$periodPhrase" />
    @else
        <div class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60 mt-1 grid gap-y-1 grid-cols-1">
            @foreach ($logs as $log)
                @php($level = $log->level)
                {{-- start log entry row --}}
                <div wire:key="log-{{ $log->id }}" x-data="{ expanded: false }" class="rounded-2xl bg-neutral-200 dark:bg-neutral-800 shadow-neu-sm dark:shadow-neu-dark-sm text-xs">
                    {{-- Grid (not flex) so the timestamp/level/source/summary
                         columns line up across every row regardless of each
                         cell's own content width — a variable-length level
                         word ("info" vs "emergency") or an absent source
                         badge no longer shifts the summary's left edge.
                         The source-badge cell always renders (even empty)
                         so it keeps its own track instead of being skipped
                         by grid auto-placement. --}}
                    <button type="button" @click="expanded = ! expanded"
                        class="grid h-11 w-full cursor-pointer grid-cols-[1.5rem_12rem_5rem_10rem_1fr] items-center gap-3 rounded-2xl pl-4 pr-2.5 text-left hover:shadow-neu dark:hover:shadow-neu-dark">
                        <span
                            class="flex h-6 w-6 items-center justify-center rounded-lg bg-neutral-200 dark:bg-neutral-800"
                            :class="expanded ? 'text-blue-500 shadow-neu-inset dark:shadow-neu-dark-inset' :
                                'text-neutral-500 shadow-neu-sm dark:shadow-neu-dark-sm'">
                            <x-monitor::chevrons-updown x-show="expanded" direction="down-up" />
                            <x-monitor::chevrons-updown x-show="! expanded" x-cloak direction="up-down" />
                        </span>
                        <span class="self-center font-mono text-neutral-400 dark:text-neutral-500">
                            {{ \LaravelMonitor\Support\Format::datetime($log->created_at, \LaravelMonitor\Support\Format::DATETIME_PRECISE) }}
                        </span>
                        <span @class([
                            'w-fit rounded px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight',
                            'monitor-log-emergency-ping bg-red-600 text-white shadow-neu-sm' =>
                                $level === 'emergency',
                            'animate-pulse bg-red-600 text-white shadow-neu-sm' => $level === 'alert',
                            'bg-red-600 text-white shadow-neu-sm' => $level === 'critical',
                            'bg-neutral-200 dark:bg-neutral-800 shadow-neu-inset dark:shadow-neu-dark-inset text-rose-600 dark:text-rose-400' =>
                                $level === 'error',
                            'bg-neutral-200 dark:bg-neutral-800 shadow-neu-inset dark:shadow-neu-dark-inset text-orange-500 dark:text-orange-300' =>
                                $level === 'warning',
                            'bg-neutral-200 dark:bg-neutral-800 shadow-neu-inset dark:shadow-neu-dark-inset text-blue-600 dark:text-sky-400' => in_array(
                                $level,
                                ['notice', 'info']),
                            'bg-neutral-200 dark:bg-neutral-800 shadow-neu-inset dark:shadow-neu-dark-inset text-neutral-500 dark:text-neutral-400' =>
                                $level === 'debug',
                        ])>{{ $level }}</span>
                        <span class="min-w-0 truncate" @click.stop>
                            @if ($log->sourceUrl)
                                <x-monitor::exception-source-badge :type="$log->sourceType" :label="$log->sourceLabel"
                                    :url="$log->sourceUrl" />
                            @else
                                <x-monitor::exception-source-badge :type="'debug'" :label="'none'"
                                    :url="$log->sourceUrl" />
                            @endif
                        </span>
                        <span class="self-center min-w-0 truncate text-neutral-700 dark:text-neutral-200"
                            title="{{ $log->summary }}">{{ $log->summary }}
                        </span>
                    </button>
                    <div x-show="expanded" x-cloak class="flex flex-col divide-y divide-neutral-300/60 dark:divide-neutral-600/60 pl-4 pr-2.5">
                        <div class="border-t border-neutral-300/60 dark:border-neutral-600/60">
                            <x-monitor::json-viewer :raw="$log->contextRaw" :tree="$log->contextTree" />
                        </div>
                    </div>
                </div>
                {{-- end log entry row --}}
            @endforeach
            @if ($hasMore)
                {{-- Infinite-scroll sentinel: enters the viewport once the
                     list is scrolled to its end, calls loadMore() (bumps
                     $limit by 20), and Livewire re-renders with the bigger
                     list. Removed from the DOM once storage runs dry, so it
                     stops firing on its own instead of needing a client-side
                     "no more results" guard. The spinner only targets the
                     loadMore() round trip (wire:target), not the unrelated
                     wire:poll refresh already running on the root div. --}}
                <div wire:key="logs-load-more-sentinel" x-intersect="$wire.loadMore()" class="flex items-center justify-center py-3">
                    {{-- wire:loading.flex (not bare wire:loading): Livewire's
                         default reveal sets display:inline-block inline,
                         which beat the flex class below and stacked the icon
                         and text instead of placing them side by side. --}}
                    <span wire:loading.flex wire:target="loadMore" class="items-center gap-2 text-xs text-neutral-400 dark:text-neutral-500">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
                        </svg>
                        <span>{{ __('monitor::messages.common.loading_more') }}<span x-data="{ dots: 1 }" x-init="setInterval(() => dots = (dots % 3) + 1, 400)" x-text="'.'.repeat(dots)" class="inline-block w-3 text-left"></span></span>
                    </span>
                </div>
            @endif
        </div>
    @endif

    {{-- Same shape as Tailwind's own animate-ping (fade to 0 while scaling
         up, cubic-bezier(0, 0, 0.2, 1) infinite), but capped at scale(1.2)
         — the default scale(2) blew the emergency badge up past its
         neighbours in the row. Tailwind's own keyframes only define 75%/100%
         (no 0%), so the browser interpolates the *whole* 0%-75% span from
         the base style to that 75% value — a continuous fade, not a pause —
         which pings back-to-back with no rest. The explicit "0%, 75%" stop
         below holds flat at the resting look instead, so the badge sits
         still for 3s (75% of the 4s cycle) between pings, then does the
         actual grow-and-fade in the last 1s (still 75%/100%, matching
         Tailwind's own pacing) before snapping back to rest. --}}
    <style>
        @keyframes monitor-log-emergency-ping {

            0%,
            75% {
                transform: scale(1);
                opacity: 1;
            }

            100% {
                transform: scale(1.1);
                opacity: 0;
            }
        }

        .monitor-log-emergency-ping {
            animation: monitor-log-emergency-ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
        }
    </style>
</div>
