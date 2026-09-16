{{-- MessageSection: collapsible headers + body for one side (request or response).
     Values are already redacted server-side by Recorders\Requests. --}}
@props(['title', 'headers' => [], 'body' => null, 'size' => null])
@php
    // A body cut by the recorder is stored as a string shorter than the original size.
    $isTruncated = is_string($body) && $size !== null && strlen($body) < $size;
    $bodyRaw = is_array($body) ? \LaravelMonitor\Support\Json::encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $body;
    $bodyTree = is_array($body) ? \LaravelMonitor\Support\JsonTree::build($body) : null;
@endphp
{{-- start card http message --}}
<x-monitor::card class="p-0" x-data="{ open: false, bodyCopied: false }">
    <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-left">
        <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $title }}</h2>
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
            :class="open ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'">
            <x-monitor::chevrons-updown x-show="open" direction="down-up"/>
            <x-monitor::chevrons-updown x-show="! open" x-cloak direction="up-down"/>
        </span>
    </button>
    <div x-show="open" x-cloak x-transition class="space-y-4 border-t border-neutral-100 p-4 dark:border-neutral-800">
        <div>
            <h3 class="mb-3 text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('monitor::messages.common.headers') }}</h3>
            <x-monitor::requests.header-list :headers="$headers"/>
        </div>
        @if ($body !== null)
            {{-- start http message body --}}
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="flex items-baseline gap-2 text-sm font-semibold text-neutral-700 dark:text-neutral-200">
                        {{ __('monitor::messages.common.body') }}
                        @if ($size !== null)
                            <span class="font-mono text-xs font-normal text-neutral-400 dark:text-neutral-500">{{ \LaravelMonitor\Support\Number::fileSize($size) }}</span>
                        @endif
                        @if ($isTruncated)
                            <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-normal text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">{{ __('monitor::messages.common.body_truncated') }}</span>
                        @endif
                    </h3>
                    <button type="button"
                            :data-tooltip="bodyCopied ? @js(__('monitor::messages.common.copied')) : @js(__('monitor::messages.common.copy'))"
                            @click="navigator.clipboard.writeText(@js($bodyRaw)); bodyCopied = true; setTimeout(() => bodyCopied = false, 1500)"
                            class="flex h-6 w-6 items-center justify-center rounded-md text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5" x-show="! bodyCopied"/>
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="bodyCopied" x-cloak
                            x-transition:enter="transition-[clip-path] ease-out duration-1000" x-transition:enter-start="[clip-path:inset(0_100%_0_0)]" x-transition:enter-end="[clip-path:inset(0_0_0_0)]"/>
                    </button>
                </div>
                <div class="rounded-md border border-neutral-100 dark:border-white/5">
                    <x-monitor::json-viewer :raw="$bodyRaw" :tree="$bodyTree"/>
                </div>
            </div>
            {{-- end http message body --}}
        @endif
    </div>
</x-monitor::card>
{{-- end card http message --}}
