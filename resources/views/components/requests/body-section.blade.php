{{-- BodySection: collapsible request body, pretty-printed JSON. Sensitive
     field values (password/token/...) are already redacted server-side by
     Recorders\Requests before storage; GET/HEAD requests never have one. --}}
@props(['body'])
@if ($body !== null)
    @php($bodyJson = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
    <x-monitor::card class="p-0" x-data="{ open: false, bodyCopied: false }">
        <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-left">
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">Body</h2>
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
                :class="open ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'">
                <x-monitor::chevrons-updown x-show="open" direction="down-up"/>
                <x-monitor::chevrons-updown x-show="! open" x-cloak direction="up-down"/>
            </span>
        </button>
        <div x-show="open" x-cloak x-transition class="border-t border-neutral-100 p-4 dark:border-neutral-800">
            @if ($body['_truncated'] ?? false)
                <p class="text-xs text-neutral-400 dark:text-neutral-500">Body omitted — {{ number_format($body['_size'] ?? 0) }} bytes exceeds the stored size limit.</p>
            @else
                <div class="relative max-h-96 overflow-auto rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                    <button type="button" title="Copy"
                            @click="navigator.clipboard.writeText(@js($bodyJson)); bodyCopied = true; setTimeout(() => bodyCopied = false, 1500)"
                            class="absolute right-2 top-2 flex h-6 w-6 items-center justify-center rounded-md bg-white/80 text-neutral-400 backdrop-blur hover:text-neutral-700 dark:bg-neutral-900/80 dark:hover:text-neutral-200">
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
