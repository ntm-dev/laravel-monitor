{{-- Exceptions recorded during one request, rendered as a fragment by
     Http\Controllers\RequestEntriesController::exceptions(). Collapsed by default. --}}
@props(['exceptions'])
{{-- start card request exceptions --}}
<x-monitor::card class="p-0">
<div x-data="{ open: false }">
    <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-left">
        <h2 class="flex items-baseline gap-2 font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('monitor::messages.nav.exceptions') }}
            <span class="font-mono text-xs font-normal text-neutral-400 dark:text-neutral-500">{{ $exceptions->count() }}</span>
        </h2>
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
            :class="open ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'">
            <x-monitor::chevrons-updown x-show="open" direction="down-up"/>
            <x-monitor::chevrons-updown x-show="! open" x-cloak direction="up-down"/>
        </span>
    </button>
    <div x-show="open" x-cloak x-transition class="divide-y divide-neutral-100 border-t border-neutral-100 p-4 dark:divide-white/5 dark:border-neutral-800">
        @foreach ($exceptions as $exception)
            @php
                $payload = $exception->payload;
                $location = isset($payload['file']) ? $payload['file'].(isset($payload['line']) ? ':'.$payload['line'] : '') : null;
            @endphp
            <div class="space-y-1 py-2.5 first:pt-0 last:pb-0">
                <div class="flex items-center gap-2">
                    <x-monitor::status-badge :handled="($exception->subtype ?? 'handled') === 'handled'"/>
                    <a href="{{ route('monitor.exceptions.show', ['hash' => $exception->key]) }}"
                       class="min-w-0 truncate font-mono text-xs font-medium text-neutral-800 hover:underline dark:text-neutral-200"
                       data-tooltip="{{ $payload['class'] ?? $exception->key }}">{{ $payload['class'] ?? $exception->key }}</a>
                    <span class="ml-auto shrink-0 font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ \LaravelMonitor\Support\Format::datetimeUs($exception->created_at) }}</span>
                </div>
                @if (! empty($payload['message']))
                    <p class="break-words text-sm text-neutral-700 dark:text-neutral-300">{{ $payload['message'] }}</p>
                @endif
                @if ($location)
                    <p class="break-all font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $location }}</p>
                @endif
            </div>
        @endforeach
    </div>
</div>
</x-monitor::card>
{{-- end card request exceptions --}}
