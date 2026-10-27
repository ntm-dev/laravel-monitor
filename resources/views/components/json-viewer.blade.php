{{-- Collapsible, syntax-coloured JSON viewer for Log Context ($tree from Support\JsonTree).
     Falls back to a plain dump when $tree is null (invalid JSON). --}}
@props(['raw', 'tree'])
@if ($tree === null)
    <pre class="max-h-64 overflow-auto rounded-md bg-white p-2 font-mono text-[11px] leading-relaxed text-neutral-700 dark:bg-neutral-950 dark:text-neutral-200"><code>{{ $raw }}</code></pre>
@else
    <div class="max-h-64 overflow-auto rounded-md bg-white p-2 font-mono text-[11px] leading-relaxed dark:bg-neutral-950">
        <x-monitor::json-node :node="$tree" :root="true" />
    </div>
@endif
