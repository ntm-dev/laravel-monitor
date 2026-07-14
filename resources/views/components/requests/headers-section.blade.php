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
                <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">Request Headers</h2>
                <x-monitor::requests.header-list :headers="$requestHeaders"/>
            </div>
            <div class="p-4">
                <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">Response Headers</h2>
                <x-monitor::requests.header-list :headers="$responseHeaders"/>
            </div>
        </div>
    </div>
</x-monitor::card>
