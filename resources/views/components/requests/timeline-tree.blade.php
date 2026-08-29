{{-- Pinned tree pane: a plain flex sibling, entirely outside the chart's
     overflow-x-auto container, so it never scrolls horizontally — no
     sticky/z-index tricks required. --}}
@props(['rows'])
<div class="w-1/5 max-w-[250px] shrink-0 overflow-hidden whitespace-nowrap">
    @foreach ($rows as $row)
        @if ($row['kind'] === 'divider')
            <div x-show="expandedTracks['{{ $row['track'] }}']"
                class="flex h-9 items-center border-t border-neutral-50 pr-3 dark:border-neutral-800/40">
                <span class="h-9 w-4 shrink-0 border-l border-neutral-300 dark:border-neutral-700"></span>
                <span
                    class="pl-2 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.other') }}</span>
            </div>
        @else
            <x-monitor::requests.timeline-row :entry="$row['entry']" :left="$row['left']" :width="$row['width']"
                :kind="$row['kind']" :track-id="$row['track']" :root-label="$row['rootLabel']" :focusable="$row['focusable']" :attempt="$row['attempt']"
                :job-status="$row['jobStatus']" :attempts-duration="$row['attemptsDuration']" :job-url="$row['jobUrl']" part="label" />
        @endif
    @endforeach
</div>
