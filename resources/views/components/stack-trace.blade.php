{{-- Exception trace, modeled on Laravel's built-in exception renderer:
     application frames are expandable accordion rows (the throw site open by
     default); consecutive vendor frames collapse into a single dashed group.
     Frame grouping and source lines are prepared by the component. --}}
@props(['groups'])
<div class="flex flex-col gap-1.5 p-2.5 md:p-2.5 lg:p-2.5">
    @foreach ($groups as $group)
        @if ($group['vendor'])
            <div x-data="{ expanded: false }" class="overflow-hidden rounded-lg border border-dashed border-neutral-300 dark:border-neutral-700 bg-neutral-50/70 dark:bg-neutral-800/70">
                <button type="button" @click="expanded = ! expanded" class="group flex h-11 w-full cursor-pointer items-center gap-3 pl-4 pr-2.5 text-left hover:bg-neutral-100/70 dark:hover:bg-neutral-800/70">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::FOLDER" x-show="! expanded" class="h-3.5 w-3.5 shrink-0 text-neutral-400 dark:text-neutral-500 group-hover:text-green-600 dark:group-hover:text-green-400"/>
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::FOLDER_OPEN" x-show="expanded" x-cloak class="h-3.5 w-3.5 shrink-0 text-neutral-400 dark:text-neutral-500 group-hover:text-green-600 dark:group-hover:text-green-400"/>
                    <span class="flex-1 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $group['count'] }} vendor {{ $group['count'] === 1 ? 'frame' : 'frames' }}</span>
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3.5 w-3.5 shrink-0 text-neutral-400 dark:text-neutral-500 transition-transform" x-bind:class="expanded ? 'rotate-180' : ''"/>
                </button>
                <div x-show="expanded" x-cloak class="divide-y divide-neutral-100 dark:divide-neutral-800 border-t border-neutral-200 dark:border-neutral-700">
                    @foreach ($group['frames'] as $frame)
                        <div class="px-4 py-3">
                            <p class="truncate font-mono text-xs text-neutral-500 dark:text-neutral-400" title="{{ $frame['label'] }}">{{ $frame['label'] }}</p>
                            <p class="truncate font-mono text-xs text-neutral-400 dark:text-neutral-500" dir="rtl" title="{{ $frame['file'] }}:{{ $frame['line'] }}">{{ $frame['file'] }}:{{ $frame['line'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            @foreach ($group['frames'] as $frame)
                <div x-data="{ expanded: {{ $frame['main'] ? 'true' : 'false' }} }" class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700 shadow-xs">
                    <div class="flex h-11 items-center gap-3 bg-white dark:bg-neutral-900 pl-4 pr-2.5 {{ $frame['has_code'] ? 'cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50' : '' }}"
                         @if ($frame['has_code']) @click="expanded = ! expanded" @endif>
                        <span class="h-2 w-2 shrink-0 rounded-full bg-rose-500"></span>
                        <div class="flex min-w-0 flex-1 items-center justify-between gap-6">
                            <span class="truncate font-mono text-xs text-neutral-800 dark:text-neutral-200" title="{{ $frame['label'] }}">{{ $frame['label'] }}</span>
                            <span class="truncate font-mono text-xs text-neutral-500 dark:text-neutral-400" dir="rtl" title="{{ $frame['file'] }}:{{ $frame['line'] }}">{{ $frame['file'] }}:{{ $frame['line'] }}</span>
                        </div>
                        @if ($frame['has_code'])
                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3.5 w-3.5 shrink-0 text-neutral-400 dark:text-neutral-500 transition-transform" x-bind:class="expanded ? 'rotate-180' : ''"/>
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
