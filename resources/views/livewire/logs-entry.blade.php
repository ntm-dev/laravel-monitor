{{-- One row of the Logs list. Rendered two ways: inline via @include() for
     the very first paint (logs.blade.php's own @forelse), and rendered to an
     HTML string server-side (Logs::renderRowsHtml()) for every later
     append/prepend/replace — #monitor-logs-list is wire:ignore'd, so Livewire
     itself never re-renders this partial's output past the first paint; the
     JS in logs.blade.php splices later batches in directly. --}}
@php($level = $log->level)
{{-- start log entry row --}}
<div wire:key="log-{{ $log->id }}" x-data="{ expanded: false }" class="rounded-lg rounded-md border border border-neutral-100 dark:border-white/5 bg-white dark:bg-white/5 shadow-xs text-xs">
    {{-- Grid (not flex) so the timestamp/level/source/summary
         columns line up across every row regardless of each
         cell's own content width — a variable-length level
         word ("info" vs "emergency") or an absent source
         badge no longer shifts the summary's left edge.
         The source-badge cell always renders (even empty)
         so it keeps its own track instead of being skipped
         by grid auto-placement. --}}
    <button type="button" @click="expanded = ! expanded"
        class="grid w-full cursor-pointer grid-cols-[1.5rem_12rem_5rem_10rem_1fr] items-center gap-3 rounded-lg pl-4 pr-2.5 text-left hover:bg-white/50 dark:hover:bg-white/5"
        :class="expanded ? 'min-h-11 py-2' : 'h-11'">
        <span
            class="flex h-6 w-6 items-center justify-center rounded-md dark:border dark:border-white/10"
            :class="expanded ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' :
                'text-neutral-500 dark:bg-white/5'">
            <x-monitor::chevrons-updown x-show="expanded" direction="down-up" />
            <x-monitor::chevrons-updown x-show="! expanded" x-cloak direction="up-down" />
        </span>
        <span class="self-center font-mono text-neutral-400 dark:text-neutral-500">
            {{ \LaravelMonitor\Support\Format::datetime($log->created_at, \LaravelMonitor\Support\Format::DATETIME_PRECISE) }}
        </span>
        <span @class([
            'w-fit rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight',
            'monitor-log-emergency-ping border-red-600 bg-red-600 text-white' =>
                $level === 'emergency',
            'animate-pulse border-red-600 bg-red-600 text-white' => $level === 'alert',
            'border-red-600 bg-red-600 text-white' => $level === 'critical',
            'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400' =>
                $level === 'error',
            'border-orange-400 dark:border-orange-300 bg-orange-50 dark:bg-orange-300/10 text-orange-400 dark:text-orange-300' =>
                $level === 'warning',
            'border-blue-600 dark:border-sky-500/30 bg-sky-50 dark:bg-sky-500/10  text-blue-600 dark:text-sky-400' => in_array(
                $level,
                ['notice', 'info']),
            'border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 text-neutral-500 dark:text-neutral-400' =>
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
        <span class="min-w-0 select-text text-neutral-700 dark:text-neutral-200"
            :class="expanded ? 'self-start whitespace-pre-wrap break-words' : 'self-center truncate'"
            data-tooltip="{{ $log->summary }}" @click.stop>{{ $log->summary }}
        </span>
    </button>
    <div x-show="expanded" x-cloak class="flex flex-col divide-y divide-neutral-200  dark:divide-white/5 pl-4 pr-2.5">
        <div class="border-t border-neutral-200 dark:border-white/5">
            <x-monitor::json-viewer :raw="$log->contextRaw" :tree="$log->contextTree" />
        </div>
    </div>
</div>
{{-- end log entry row --}}
