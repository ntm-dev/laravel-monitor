{{-- Exception trace modeled on Laravel's exception renderer: app frames are accordion rows
     (throw site open), consecutive vendor frames collapse into one group. Frame labels carry
     data-line-code so layout.blade.php's highlight.js hook colours them; the "(arg types)"
     suffix is what lets hljs tokenize them. Use only Tailwind's default opacity scale (CDN JIT). --}}
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
                            {{-- Full-contrast label, distinct from the muted file path below. --}}
                            <span class="truncate font-mono text-xs text-neutral-900 dark:text-neutral-100" data-line-code data-tooltip="{{ $displayLabel }}">{{ $displayLabel }}</span>
                            {{-- ltr here: the path has its own row, so no rtl truncation trick is needed. --}}
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
