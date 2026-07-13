{{-- Exception status pill: prominent red for unhandled, neutral grey for handled. --}}
@props(['handled' => true])
@if ($handled)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-md border border-neutral-200 bg-neutral-100 px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-tight text-neutral-600']) }}>
        <span class="h-1.5 w-1.5 rounded-full bg-neutral-400"></span>Handled
    </span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-tight text-rose-600']) }}>
        <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>Unhandled
    </span>
@endif
