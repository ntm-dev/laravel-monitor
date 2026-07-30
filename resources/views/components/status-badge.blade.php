{{-- Exception status pill: prominent red for unhandled, blue for handled. --}}
@props(['handled' => true])
@if ($handled)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-md border border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-tight text-blue-600 dark:text-blue-400']) }}>
        <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>Handled
    </span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-md border border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-tight text-rose-600 dark:text-rose-400']) }}>
        <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>Unhandled
    </span>
@endif
