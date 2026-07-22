{{-- BodySection: collapsible request body, pretty-printed JSON. Sensitive
     field values (password/token/...) are already redacted server-side by
     Recorders\Requests before storage; GET/HEAD requests never have one. --}}
@props(['body'])
@if ($body !== null)
    <x-monitor::card class="p-0" x-data="{ open: false }">
        <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-left">
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">Body</h2>
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2"
                class="h-4 w-4 text-neutral-400 transition-transform" x-bind:class="open ? 'rotate-180' : ''"/>
        </button>
        <div x-show="open" x-cloak x-transition class="border-t border-neutral-100 p-4 dark:border-neutral-800">
            @if ($body['_truncated'] ?? false)
                <p class="text-xs text-neutral-400 dark:text-neutral-500">Body omitted — {{ number_format($body['_size'] ?? 0) }} bytes exceeds the stored size limit.</p>
            @else
                <div class="max-h-96 overflow-auto rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900"><pre class="whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-neutral-800 dark:text-neutral-200">{{ json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
            @endif
        </div>
    </x-monitor::card>
@endif
