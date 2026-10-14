{{-- Logs written during one request, rendered as a fragment by
     Http\Controllers\RequestEntriesController::logs(). Collapsed by default. --}}
@props(['logs'])
{{-- start card request logs --}}
<x-monitor::card class="p-0">
<div x-data="{ open: false }">
    <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-left">
        <h2 class="flex items-baseline gap-2 font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('monitor::messages.nav.logs') }}
            <span class="font-mono text-xs font-normal text-neutral-400 dark:text-neutral-500">{{ $logs->count() }}</span>
        </h2>
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
            :class="open ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'">
            <x-monitor::chevrons-updown x-show="open" direction="down-up"/>
            <x-monitor::chevrons-updown x-show="! open" x-cloak direction="up-down"/>
        </span>
    </button>
    <div x-show="open" x-cloak x-transition class="space-y-2 border-t border-neutral-100 p-4 dark:border-neutral-800">
        @foreach ($logs as $log)
            @include('monitor::livewire.logs-entry', ['log' => $log, 'compact' => true])
        @endforeach
    </div>
</div>
</x-monitor::card>
{{-- end card request logs --}}
