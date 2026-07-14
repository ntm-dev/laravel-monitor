{{-- Source snippet for a stack frame. Renders a plain, line-numbered fallback
     that highlight.js upgrades in the browser (progressive enhancement, mirroring
     Laravel's own exception renderer). $lines is prepared by the component. --}}
@props(['lines' => []])
@if (empty($lines))
    <div class="border-t border-neutral-100 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-800/50 px-4 py-6 text-center font-mono text-xs text-neutral-400 dark:text-neutral-500">Source not available for this frame.</div>
@else
    <div class="overflow-x-auto border-t border-neutral-100 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-800/50 py-1 font-mono text-xs leading-relaxed" data-frame-code>
        @foreach ($lines as $srcLine)
            <div class="flex {{ $srcLine['error'] ? 'bg-rose-100 dark:bg-rose-500/15' : '' }}">
                <span class="w-12 shrink-0 select-none px-2 text-right {{ $srcLine['error'] ? 'text-rose-500 dark:text-rose-400' : 'text-neutral-400 dark:text-neutral-500' }}">{{ $srcLine['number'] }}</span>
                <code data-line-code class="whitespace-pre border-l-2 py-0.5 pl-3 pr-6 text-neutral-800 dark:text-neutral-200 {{ $srcLine['error'] ? 'border-rose-500' : 'border-transparent' }}">{{ $srcLine['code'] === '' ? ' ' : $srcLine['code'] }}</code>
            </div>
        @endforeach
    </div>
@endif
