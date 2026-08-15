{{-- One line (or nested block) of the Log Context JSON viewer. A scalar
     renders as a single colour-coded line; an object/array renders its own
     brace line with a small solid +/- toggle button right after the opening
     brace (ported from Nightwatch's log-context JSON viewer), recursing into
     x-monitor::json-node for each child — each nested node gets its own
     Alpine x-data, so containers collapse independently rather than sharing
     one controller (mirrors the app's existing nested-accordion pattern, see
     stack-trace.blade.php's vendor-frame groups). $node/$last are prepared
     by Support\JsonTree / the parent x-monitor::json-node call ($loop->last).
     $root is only ever true for x-monitor::json-viewer's own top-level call
     — the outermost object/array is always shown fully expanded with no
     toggle of its own, since collapsing it would just collapse the whole
     panel back to a single "N items" line. --}}
@props(['node', 'last' => true, 'root' => false])
@if ($node->isContainer())
    {{-- start json node container --}}
    <div x-data="{ expanded: true }">
        <div class="flex items-start gap-1">
            @if ($node->key !== null)
                <span class="text-blue-600 dark:text-sky-400">"{{ $node->key }}"</span><span class="text-neutral-500 dark:text-neutral-400">:</span>
            @endif
            <span class="text-neutral-500 dark:text-neutral-400">{{ $node->type === 'array' ? '[' : '{' }}</span>
            @unless ($root)
                <button type="button" @click="expanded = ! expanded"
                    class="inline-flex shrink-0 translate-y-px items-center justify-center gap-1 rounded-sm bg-blue-500 text-[10px] leading-none text-white hover:bg-blue-600 dark:bg-emerald-500 dark:hover:bg-emerald-600"
                    :class="expanded ? 'h-3.5 w-3.5' : 'h-4 px-1.5'">
                    <svg x-show="expanded" class="h-[8px] w-[8px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ \LaravelMonitor\Support\Icons::MINUS }}" />
                    </svg>
                    <svg x-show="! expanded" x-cloak class="h-[8px] w-[8px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ \LaravelMonitor\Support\Icons::PLUS }}" />
                    </svg>
                    <span x-show="! expanded" x-cloak>{{ $node->count() }} {{ trans_choice('monitor::messages.common.json_items', $node->count()) }}</span>
                </button>
                <span x-show="! expanded" x-cloak class="text-neutral-500 dark:text-neutral-400">{{ $node->type === 'array' ? ']' : '}' }}{{ $last ? '' : ',' }}</span>
            @endunless
        </div>
        <div x-show="expanded" x-cloak class="pl-4">
            @foreach ($node->children as $child)
                <x-monitor::json-node :node="$child" :last="$loop->last" />
            @endforeach
        </div>
        <div x-show="expanded" x-cloak class="text-neutral-500 dark:text-neutral-400">{{ $node->type === 'array' ? ']' : '}' }}{{ $last ? '' : ',' }}</div>
    </div>
    {{-- end json node container --}}
@else
    {{-- start json node leaf --}}
    <div class="flex items-start gap-1">
        @if ($node->key !== null)
            <span class="text-blue-600 dark:text-sky-400">"{{ $node->key }}"</span><span class="text-neutral-500 dark:text-neutral-400">:</span>
        @endif
        @switch($node->type)
            @case('string')
                <span class="break-all text-emerald-600 dark:text-emerald-400">"{{ $node->value }}"</span>
                @break
            @case('number')
                <span class="text-amber-600 dark:text-amber-400">{{ $node->value }}</span>
                @break
            @case('bool')
                <span class="italic text-purple-600 dark:text-purple-400">{{ $node->value ? 'true' : 'false' }}</span>
                @break
            @default
                <span class="italic text-purple-600 dark:text-purple-400">null</span>
        @endswitch
        <span class="text-neutral-500 dark:text-neutral-400">{{ $last ? '' : ',' }}</span>
    </div>
    {{-- end json node leaf --}}
@endif
