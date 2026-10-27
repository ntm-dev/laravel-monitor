{{-- Click-to-change priority dropdown for the Issues list; saves immediately.
     The menu is teleported to body with fixed coords so it never resizes the table, and opens
     upward near the viewport bottom, using a fixed menuHeight estimate to decide before paint. --}}
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
    <button type="button" x-ref="trigger" @click="reposition(); open = ! open" data-tooltip="{{ \LaravelMonitor\Support\Format::priorityLabel($priority) }}">
        <x-monitor::priority-bars :priority="$priority"/>
    </button>
    <template x-teleport="body">
        <div x-show="open" x-cloak @click.outside="open = false"
             :style="`top: ${top}px; left: ${left}px;`"
             class="fixed z-30 w-40 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
            @foreach ($levels as $level)
                <button type="button" @click="open = false"
                        @if ($priority !== $level) wire:click="setPriority({{ Js::from($type) }}, {{ Js::from($issueKey) }}, {{ Js::from($level) }})" @endif
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
