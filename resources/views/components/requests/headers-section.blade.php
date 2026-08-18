{{-- HeadersSection: collapsible request/response headers. Sensitive header
     values (auth/cookies) are already redacted server-side by
     Recorders\Requests before storage. --}}
@props(['requestHeaders' => [], 'responseHeaders' => []])
<x-monitor::card class="p-0" x-data="{ open: false }">
    <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-left">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.headers') }}</h2>
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
            :class="open ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'">
            <x-monitor::chevrons-updown x-show="open" direction="down-up"/>
            <x-monitor::chevrons-updown x-show="! open" x-cloak direction="up-down"/>
        </span>
    </button>
    <div x-show="open" x-cloak x-transition class="shadow-[0_-1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_-1px_0_rgba(255,255,255,0.06)]">
        <div class="grid grid-cols-1 divide-y divide-neutral-300/60 md:grid-cols-2 md:divide-x md:divide-y-0 dark:divide-neutral-600/60">
            <div class="p-4">
                <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.request_headers') }}</h2>
                <x-monitor::requests.header-list :headers="$requestHeaders"/>
            </div>
            <div class="p-4">
                <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.response_headers') }}</h2>
                <x-monitor::requests.header-list :headers="$responseHeaders"/>
            </div>
        </div>
    </div>
</x-monitor::card>
