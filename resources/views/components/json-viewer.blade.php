{{-- Syntax-highlighted, collapsible JSON viewer for Log Context — same idea
     as Nightwatch's log-detail JSON panel: colour per token type, and every
     object/array is independently expandable. Falls back to a plain dump
     when $tree is null (payload wasn't valid JSON). $tree is prepared by
     Support\JsonTree; this component only walks and displays it. --}}
@props(['raw', 'tree'])
@if ($tree === null)
    <pre class="max-h-64 overflow-auto rounded-md bg-white p-2 font-mono text-[11px] leading-relaxed text-neutral-700 dark:bg-neutral-950 dark:text-neutral-200"><code>{{ $raw }}</code></pre>
@else
    <div class="max-h-64 overflow-auto rounded-md bg-white p-2 font-mono text-[11px] leading-relaxed dark:bg-neutral-950">
        <x-monitor::json-node :node="$tree" :root="true" />
    </div>
@endif
