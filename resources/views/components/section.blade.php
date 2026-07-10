{{-- Nightwatch-style section frame: icon chip + title header, optional actions slot. --}}
@props(['icon', 'title'])
<div {{ $attributes->merge(['class' => 'rounded-xl border border-neutral-200/70 bg-white/50 p-1.5']) }}>
    <div class="flex items-center justify-between px-1 pb-2.5 pt-1.5">
        <div class="flex items-center gap-2.5">
            <span class="flex h-7 w-7 items-center justify-center rounded-md border border-neutral-200 bg-white text-neutral-500 shadow-sm">
                <x-monitor::icon :path="$icon"/>
            </span>
            <h2 class="font-semibold text-neutral-900">{{ $title }}</h2>
        </div>
        {{ $actions ?? '' }}
    </div>
    {{ $slot }}
</div>
