{{-- 4 ascending bars; lit count and colour encode the priority level.
     priority="none" is used for the Issues priority sort header. --}}
@props(['priority' => 'none'])
@php
    $litCount = match ($priority) {
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'urgent' => 4,
        default => 0,
    };
    $litColor = match ($priority) {
        'low' => 'bg-blue-500',
        'medium' => 'bg-amber-500',
        'high', 'urgent' => 'bg-rose-500',
        default => null,
    };
    $dimColor = 'bg-neutral-300 dark:bg-neutral-600';
@endphp
<span {{ $attributes->class(['inline-flex h-4 items-end gap-0.5', 'animate-pulse' => $priority === 'urgent']) }}>
    @for ($bar = 1; $bar <= 4; $bar++)
        <span class="w-[3px] rounded-sm {{ $bar <= $litCount ? $litColor : $dimColor }}" style="height: {{ $bar * 3 + 2 }}px"></span>
    @endfor
</span>
