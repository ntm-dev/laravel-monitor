{{-- 4 ascending bars, replacing the old single-icon priority indicator so
     the level itself (not just its colour) is legible at a glance: how many
     bars are lit, and which colour, both encode the level — low lights the
     shortest bar blue, medium the two shortest orange, high the three
     shortest red, urgent lights all four red and pulses. Also reused,
     always with priority="none", as the priority-column sort toggle in
     issues.blade.php's <thead>. --}}
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
