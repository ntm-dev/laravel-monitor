{{-- Priority bars as a click-to-change dropdown, right in the Issues list —
     mirrors Laravel Nightwatch's own issue list (click the bars, pick a
     level, it saves immediately) rather than requiring a trip to the Issue
     detail page just to change this one field.

     The menu is x-teleport'd to <body> and positioned with `fixed` coords
     read from the trigger button's own getBoundingClientRect() — kept OUT
     of the table's DOM subtree entirely, rather than `absolute` inside the
     <td>. An absolutely-positioned popover left inside the table still
     inflates the nearest scrollable ancestor's painted height while open
     (nothing here clips overflow-y), which visibly grew/shrank the whole
     table every time the popover opened/closed.

     Flips to open upward for a trigger near the bottom of the viewport
     (e.g. the last few rows) instead of always opening downward, which
     would otherwise render mostly off-screen/clipped there. Uses a fixed
     menuHeight estimate (5 same-height rows, see the list below) rather
     than measuring the real element, so the flip decision is known before
     first paint — no visible jump from "open below" to "open above". --}}
@props(['type', 'issueKey', 'priority'])
@php
    $levels = ['none', 'low', 'medium', 'high', 'urgent'];
@endphp
<div x-data="{
        open: false,
        top: 0,
        left: 0,
        reposition() {
            const rect = this.$refs.trigger.getBoundingClientRect();
            const menuHeight = 168;
            const opensUp = window.innerHeight - rect.bottom < menuHeight + 8;
            this.top = opensUp ? Math.max(8, rect.top - menuHeight - 4) : rect.bottom + 4;
            this.left = rect.left;
        },
     }" class="inline-block">
    <button type="button" x-ref="trigger" @click="reposition(); open = ! open" title="{{ \LaravelMonitor\Support\Format::priorityLabel($priority) }}">
        <x-monitor::priority-bars :priority="$priority"/>
    </button>
    <template x-teleport="body">
        <div x-show="open" x-cloak @click.outside="open = false"
             :style="`top: ${top}px; left: ${left}px;`"
             class="fixed z-30 w-40 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
            @foreach ($levels as $level)
                <button type="button" @click="open = false"
                        wire:click="setPriority({{ Js::from($type) }}, {{ Js::from($issueKey) }}, {{ Js::from($level) }})"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs hover:bg-neutral-50 dark:hover:bg-neutral-800 {{ $priority === $level ? 'text-neutral-900 dark:text-neutral-100' : 'text-neutral-500 dark:text-neutral-400' }}">
                    <x-monitor::priority-bars :priority="$level"/>
                    <span class="flex-1">{{ \LaravelMonitor\Support\Format::priorityLabel($level) }}</span>
                    @if ($priority === $level)
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3 w-3"/>
                    @endif
                </button>
            @endforeach
        </div>
    </template>
</div>
