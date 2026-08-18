{{-- Exception status pill: prominent red for unhandled, blue for handled. --}}
@props(['handled' => true])
@if ($handled)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-md bg-neutral-200 dark:bg-neutral-800 px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-tight text-blue-600 shadow-neu-inset dark:text-blue-400 dark:shadow-neu-dark-inset']) }}>
        <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>{{ __('monitor::messages.common.handled') }}
    </span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-md bg-neutral-200 dark:bg-neutral-800 px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-tight text-rose-600 shadow-neu-inset dark:text-rose-400 dark:shadow-neu-dark-inset']) }}>
        <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>{{ __('monitor::messages.common.unhandled') }}
    </span>
@endif
