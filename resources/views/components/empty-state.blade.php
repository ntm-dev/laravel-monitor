{{-- "All clear" card: badge, message, dashed check circle, NO ACTIONS footer. --}}
@props(['label', 'message', 'periodPhrase' => null])
<x-monitor::card {{ $attributes->merge(['class' => 'flex flex-col p-4']) }}>
    <x-monitor::badge>{{ $label }}</x-monitor::badge>
    <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900 dark:text-neutral-100">{{ $message }}{{ $periodPhrase ? ' '.$periodPhrase : '' }}.</p>
    <div class="flex flex-1 items-center justify-center py-10">
        <svg viewBox="0 0 202 202" fill="none" xmlns="http://www.w3.org/2000/svg"
             class="h-[120px] w-[120px] text-emerald-500/80 md:h-[180px] md:w-[180px] dark:text-emerald-800"
             style="mask-image: linear-gradient(to top, rgba(0,0,0,0.2), black); -webkit-mask-image: linear-gradient(to top, rgba(0,0,0,0.2), black);">
            <path d="M53.0146 107.96L80.4348 135.799L148.985 66.2017" stroke="currentColor" stroke-width="2" stroke-miterlimit="10" stroke-linecap="square"/>
            <path d="M101 201C156.228 201 201 156.228 201 101C201 45.7715 156.228 1 101 1C45.7715 1 1 45.7715 1 101C1 156.228 45.7715 201 101 201Z"
                  stroke="currentColor" stroke-width="2" stroke-miterlimit="10" stroke-linecap="round"
                  style="stroke-dasharray: 4 7; stroke-dashoffset: 11;"/>
        </svg>
    </div>
    <div class="flex items-center justify-end gap-1.5 font-mono text-xs uppercase tracking-tight text-neutral-600 dark:text-neutral-300">
        <svg class="h-4 w-4 text-emerald-500 dark:text-emerald-400" viewBox="0 0 24 24" fill="currentColor">
            <path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/>
        </svg>
        No actions
    </div>
</x-monitor::card>
