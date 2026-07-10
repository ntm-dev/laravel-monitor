{{-- "All clear" card: badge, message, dashed check circle, NO ACTIONS footer. --}}
@props(['label', 'message', 'periodPhrase' => null])
<x-monitor::card {{ $attributes->merge(['class' => 'flex flex-col p-4']) }}>
    <x-monitor::badge>{{ $label }}</x-monitor::badge>
    <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900">{{ $message }}{{ $periodPhrase ? ' '.$periodPhrase : '' }}.</p>
    <div class="flex flex-1 items-center justify-center py-10">
        <div class="flex h-44 w-44 items-center justify-center rounded-full border-2 border-dashed border-teal-200">
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="1" class="h-16 w-16 text-teal-300"/>
        </div>
    </div>
    <div class="flex items-center justify-end gap-1.5 font-mono text-xs uppercase tracking-tight text-neutral-600">
        <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="currentColor">
            <path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/>
        </svg>
        No actions
    </div>
</x-monitor::card>
