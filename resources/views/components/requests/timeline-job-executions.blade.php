{{-- Expandable list of a dispatched job's own resolved execution(s) —
     mirrors the vendor-frame collapse in stack-trace.blade.php: plain text
     rows, not a second proportional bar. A queue worker can pick a job up
     seconds or minutes after dispatch, far outside the dispatching root's
     own (often sub-second) duration, so there's no shared time scale the
     dispatch row and the job's own run could both stay legible on — see
     Support\Timeline::attachJobExecutions() for why this is metadata to
     expand inline instead of spliced onto the waterfall itself.

     Rendered identically into both panes (unlike a normal event row, which
     splits compact tree-pane text from a positioned chart-pane bar) so
     expanding it grows both panes by the same row count and they stay in
     lockstep — toggled by the shared `expandedJobs` Alpine state in
     timeline.blade.php, keyed by the dispatch row's own entry id. --}}
@props(['entry'])
@php($fmt = fn ($ms) => $ms !== null ? \LaravelMonitor\Support\Format::duration($ms) : null)
<div x-show="expandedJobs['{{ $entry->id }}']" x-cloak class="divide-y divide-neutral-100 dark:divide-neutral-800/60">
    @foreach ($entry->metadata['executions'] as $execution)
        <div class="flex h-9 items-center gap-1.5 whitespace-nowrap pl-8 pr-3">
            <span @class([
                'h-1.5 w-1.5 shrink-0 rounded-full',
                'bg-emerald-500' => $execution['subtype'] === 'processed',
                'bg-rose-500' => $execution['subtype'] === 'failed',
                'bg-amber-500' => $execution['subtype'] === 'released',
            ])></span>
            <span @class([
                'shrink-0 font-mono text-[11px] font-medium uppercase',
                'text-emerald-600 dark:text-emerald-400' => $execution['subtype'] === 'processed',
                'text-rose-600 dark:text-rose-400' => $execution['subtype'] === 'failed',
                'text-amber-600 dark:text-amber-400' => $execution['subtype'] === 'released',
            ])>{{ $execution['subtype'] }}</span>
            @if ($execution['duration'] !== null)
                <span class="font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $fmt($execution['duration']) }}</span>
            @endif
            @if ($execution['attempt'] > 1)
                <span class="font-mono text-[10px] text-neutral-400 dark:text-neutral-500" title="Attempt">#{{ $execution['attempt'] }}</span>
            @endif
            @if ($execution['attempt_request_id'] ?? null)
                <a href="{{ route('monitor.jobs.attempts.show', $execution['attempt_request_id']) }}" title="View this job's own page"
                   class="ml-auto flex h-5 w-5 shrink-0 items-center justify-center rounded border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-neutral-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-200">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                </a>
            @endif
        </div>
        @foreach ($execution['children'] as $child)
            <div class="flex h-9 items-center gap-1.5 whitespace-nowrap pl-12 pr-3">
                <span class="shrink-0 font-mono text-[11px] font-medium text-neutral-700 dark:text-neutral-200">{{ $child['badge'] }}</span>
                @if ($child['duration'] !== null)
                    <span class="shrink-0 font-mono text-[11px] text-neutral-400 dark:text-neutral-500">{{ $fmt($child['duration']) }}</span>
                @endif
                <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500" title="{{ $child['detail'] }}">{{ $child['detail'] }}</span>
            </div>
        @endforeach
    @endforeach
</div>
