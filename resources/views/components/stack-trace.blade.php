{{-- Exception trace, modeled on Laravel's built-in exception renderer
     (vendor/laravel/framework/.../exceptions/renderer/components/{trace,frame,
     vendor-frames,vendor-frame}.blade.php): application frames are
     expandable accordion rows (the throw site open by default); consecutive
     vendor frames collapse into a single dashed group whose own frames, once
     expanded, render as bare label/file:line cards — not accordion rows,
     since vendor frames never carry a source snippet. Frame grouping and
     source lines are prepared by the component. Accent colour is blue in
     light mode / emerald in dark mode, matching Laravel's own scheme
     (:class="{{ 'blue-500 dark:emerald-500' }}" below), not this app's usual
     all-mode blue, specifically so this one view reads as "Laravel's trace"
     rather than a reskin.

     Laravel's own frame title is PHP-syntax-highlighted (its
     formatted-source component runs the "Class->method(args)" text through
     Shiki), not the plain label the two `data-line-code` spans below used to
     render — we don't have Shiki, so they piggyback on the highlight.js hook
     already wired up in layout.blade.php for frame-code's source snippets
     (`document.querySelectorAll('[data-line-code]')...window.hljs.highlight`),
     which upgrades any element carrying that attribute on page load/Livewire
     morph, not just <pre><code> blocks.

     $displayLabel appends "(arg types)" (unless it's the bare "{main}" entry
     frame), matching Laravel's own Frame::args()/formatted-source: the
     parens aren't just cosmetic, they're load-bearing for the highlighting
     above too — hljs's php grammar doesn't tokenize a bare "Class->method"
     fragment at all (no `(`), so data-line-code upgrades it to a no-op
     (verified against the actual hljs build this app loads), while
     "Class->method(...)" gets the class/arrow/method spans coloured. The arg
     list itself is *types only* — "object(App\Services\Foo)", "string",
     "integer" — never real values; see Recorders\Exceptions::argTypes() for
     why that's safe to have persisted at all. Frames recorded before this
     field existed just render with empty parens ($frame['args'] ?? []).

     Opacity modifiers below (dark:bg-white/5, /10, ...) are restricted to
     Tailwind's default scale (0/5/10/20/25/30/40/50/...) on purpose — this
     app loads Tailwind via the browser `cdn.tailwindcss.com` script, not a
     compiled build, and that JIT silently drops any `/N` color-opacity
     modifier outside that scale (no CSS rule at all, not even a wrong one).
     Laravel's own reference markup uses off-scale values like `bg-white/1`,
     `/2`, `/3`, `/8` (its build pipeline supports arbitrary values); copying
     those literally here compiled to nothing, so a "translucent dark tint"
     class silently lost its dark: half and fell back to whatever untinted
     class sat next to it — e.g. a card stuck on its light-mode `bg-white`
     forever, however the theme was set. --}}
@props(['groups'])
<div class="flex flex-col gap-1.5 p-2.5 md:p-2.5 lg:p-2.5">
    @foreach ($groups as $group)
        @if ($group['vendor'])
            <div x-data="{ expanded: false }" class="group rounded-lg border border-neutral-200 dark:border-white/5"
                 :class="expanded ? 'bg-white dark:bg-white/5 shadow-xs' : 'border-dashed border-neutral-300 bg-neutral-50 opacity-90 dark:border-white/10 dark:bg-white/5'">
                <button type="button" @click="expanded = ! expanded" class="flex h-11 w-full cursor-pointer items-center gap-3 rounded-lg pl-4 pr-2.5 text-left hover:bg-white/50 dark:hover:bg-white/5">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::FOLDER" :stroke="2" x-show="! expanded" x-cloak class="h-3 w-3 shrink-0 text-neutral-400 group-hover:text-blue-500 dark:text-neutral-500 dark:group-hover:text-emerald-500"/>
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::FOLDER_OPEN" :stroke="2" x-show="expanded" class="h-3 w-3 shrink-0 text-blue-500 dark:text-emerald-500"/>
                    <span class="flex-1 font-mono text-xs leading-3 text-neutral-900 dark:text-neutral-400">{{ $group['count'] }} {{ trans_choice('monitor::messages.common.vendor_frames', $group['count']) }}</span>
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
                          :class="expanded ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'">
                        <x-monitor::chevrons-updown x-show="expanded" direction="down-up"/>
                        <x-monitor::chevrons-updown x-show="! expanded" x-cloak direction="up-down"/>
                    </span>
                </button>
                <div x-show="expanded" x-cloak class="flex flex-col divide-y divide-neutral-200 border-t border-neutral-200 dark:divide-white/5 dark:border-white/5">
                    @foreach ($group['frames'] as $frame)
                        @php($displayLabel = $frame['label'] === '{main}' ? $frame['label'] : $frame['label'].'('.implode(', ', $frame['args'] ?? []).')')
                        <div class="grid gap-3 rounded-lg bg-neutral-50 p-4 dark:bg-transparent">
                            {{-- text-neutral-900/100 (not the file span's
                                 muted 500/400 below): Laravel's own
                                 vendor-frame renders this through
                                 formatted-source, whose base/un-highlighted
                                 text colour is near-black in light mode and
                                 near-white in dark mode — a full-contrast
                                 label reading as clearly distinct from the
                                 muted file path underneath it, not the same
                                 grey repeated twice. --}}
                            <span class="truncate font-mono text-xs text-neutral-900 dark:text-neutral-100" data-line-code data-tooltip="{{ $displayLabel }}">{{ $displayLabel }}</span>
                            {{-- ltr (not the app-frame header's rtl truncation trick below):
                                 this file:line sits on its own full-width row here, not
                                 sharing one with the label, so there's no front text to
                                 protect from truncation — ltr just reads naturally
                                 left-aligned under the label, matching Laravel's own
                                 vendor-frame (file-with-line defaults to ltr; only the
                                 frame header overrides it). rtl here left it flush right
                                 instead, reading as detached from the label above it. --}}
                            <span class="truncate font-mono text-xs text-neutral-500 dark:text-neutral-400" data-tooltip="{{ $frame['file'] }}:{{ $frame['line'] }}">{{ $frame['file'] }}:{{ $frame['line'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            @foreach ($group['frames'] as $frame)
                @php($displayLabel = $frame['label'] === '{main}' ? $frame['label'] : $frame['label'].'('.implode(', ', $frame['args'] ?? []).')')
                <div x-data="{ expanded: {{ $frame['main'] ? 'true' : 'false' }} }" class="group overflow-hidden rounded-lg border border-neutral-200 dark:border-white/10 shadow-xs" :class="expanded ? 'dark:border-white/5' : ''">
                    <div class="flex h-11 items-center gap-3 bg-white dark:bg-white/5 pl-4 pr-2.5 overflow-x-auto"
                         :class="{{ $frame['has_code'] ? "'cursor-pointer hover:bg-neutral-50 dark:hover:bg-white/5 hover:text-blue-500 dark:hover:text-emerald-500' + (expanded ? ' dark:bg-white/5 rounded-t-lg' : ' rounded-lg')" : "'rounded-lg'" }}"
                         @if ($frame['has_code']) @click="expanded = ! expanded" @endif>
                        <div class="flex size-3 shrink-0 items-center justify-center">
                            <div class="size-2 rounded-full" :class="expanded ? 'bg-rose-500 dark:bg-neutral-400' : 'bg-rose-200 dark:bg-neutral-700'"></div>
                        </div>
                        <div class="flex min-w-0 flex-1 items-center justify-between gap-6">
                            <span class="truncate font-mono text-xs text-neutral-800 dark:text-neutral-200" data-line-code data-tooltip="{{ $displayLabel }}">{{ $displayLabel }}</span>
                            <span class="truncate font-mono text-xs text-neutral-500 dark:text-neutral-400" dir="rtl" data-tooltip="{{ $frame['file'] }}:{{ $frame['line'] }}">{{ $frame['file'] }}:{{ $frame['line'] }}</span>
                        </div>
                        @if ($frame['has_code'])
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
                                  :class="expanded ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'">
                                <x-monitor::chevrons-updown x-show="expanded" direction="down-up"/>
                                <x-monitor::chevrons-updown x-show="! expanded" x-cloak direction="up-down"/>
                            </span>
                        @endif
                    </div>
                    @if ($frame['has_code'])
                        <div x-show="expanded" @if (! $frame['main']) x-cloak @endif>
                            <x-monitor::frame-code :lines="$frame['lines']"/>
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    @endforeach
</div>
