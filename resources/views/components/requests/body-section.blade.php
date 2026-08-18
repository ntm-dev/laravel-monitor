{{-- BodySection: collapsible request body, pretty-printed JSON. Sensitive
     field values (password/token/...) are already redacted server-side by
     Recorders\Requests before storage; GET/HEAD requests never have one. --}}
@props(['body'])
@if ($body !== null)
    @php($bodyJson = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
    <x-monitor::card class="p-0" x-data="{ open: false, bodyCopied: false }">
        <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-left">
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.body') }}</h2>
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-neutral-200 dark:bg-neutral-800"
                :class="open ? 'text-blue-500 shadow-neu-inset dark:shadow-neu-dark-inset' : 'text-neutral-500 shadow-neu-sm dark:shadow-neu-dark-sm'">
                <x-monitor::chevrons-updown x-show="open" direction="down-up"/>
                <x-monitor::chevrons-updown x-show="! open" x-cloak direction="up-down"/>
            </span>
        </button>
        <div x-show="open" x-cloak x-transition class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] p-4">
            @if ($body['_truncated'] ?? false)
                <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.body_omitted', ['size' => number_format($body['_size'] ?? 0)]) }}</p>
            @else
                <div class="relative max-h-96 overflow-auto rounded-xl bg-neutral-200 p-3 shadow-neu-inset dark:bg-neutral-800 dark:shadow-neu-dark-inset">
                    <button type="button" title="{{ __('monitor::messages.common.copy') }}"
                            @click="navigator.clipboard.writeText(@js($bodyJson)); bodyCopied = true; setTimeout(() => bodyCopied = false, 1500)"
                            class="absolute right-2 top-2 flex h-6 w-6 items-center justify-center rounded-lg bg-neutral-200/80 text-neutral-400 backdrop-blur hover:text-neutral-700 dark:bg-neutral-800/80 dark:hover:text-neutral-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5" x-show="! bodyCopied"/>
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="bodyCopied" x-cloak
                            x-transition:enter="transition-[clip-path] ease-out duration-1000" x-transition:enter-start="[clip-path:inset(0_100%_0_0)]" x-transition:enter-end="[clip-path:inset(0_0_0_0)]"/>
                    </button>
                    {{-- highlight.js hook re-applies on load/Livewire morph — see
                         layout.blade.php's `[data-line-code]` query. --}}
                    <pre class="whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-neutral-800 dark:text-neutral-200"><code data-line-code data-lang="json">{{ $bodyJson }}</code></pre>
                </div>
            @endif
        </div>
    </x-monitor::card>
@endif
