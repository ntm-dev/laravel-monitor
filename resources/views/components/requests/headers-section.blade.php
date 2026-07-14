{{-- HeadersSection: collapsible request/response headers. Sensitive header
     values (auth/cookies) are already redacted server-side by
     Recorders\Requests before storage. --}}
@props(['requestHeaders' => [], 'responseHeaders' => []])
<x-monitor::card class="p-0" x-data="{ open: false }">
    <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-left">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">Headers</h2>
        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2"
            class="h-4 w-4 text-neutral-400 transition-transform" x-bind:class="open ? 'rotate-180' : ''"/>
    </button>
    <div x-show="open" x-cloak x-transition class="border-t border-neutral-100 dark:border-neutral-800">
        <div class="grid grid-cols-1 divide-y divide-neutral-100 md:grid-cols-2 md:divide-x md:divide-y-0 dark:divide-neutral-800">
            <div class="p-4">
                <h3 class="mb-2 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Request Headers</h3>
                @if (empty($requestHeaders))
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">No headers recorded.</p>
                @else
                    <dl class="space-y-1.5">
                        @foreach ($requestHeaders as $name => $value)
                            <div class="flex items-start gap-3 text-xs">
                                <dt class="w-40 shrink-0 truncate text-neutral-500 dark:text-neutral-400" title="{{ $name }}">{{ $name }}</dt>
                                <dd class="min-w-0 flex-1 break-words font-mono text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
            <div class="p-4">
                <h3 class="mb-2 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Response Headers</h3>
                @if (empty($responseHeaders))
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">No headers recorded.</p>
                @else
                    <dl class="space-y-1.5">
                        @foreach ($responseHeaders as $name => $value)
                            <div class="flex items-start gap-3 text-xs">
                                <dt class="w-40 shrink-0 truncate text-neutral-500 dark:text-neutral-400" title="{{ $name }}">{{ $name }}</dt>
                                <dd class="min-w-0 flex-1 break-words font-mono text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </div>
    </div>
</x-monitor::card>
