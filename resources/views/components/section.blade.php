{{-- Section frame: icon chip + title header, optional actions slot. --}}
@props(['icon' => null, 'title' => null, 'iconViewBox' => '0 0 24 24', 'iconFill' => 'none', 'iconTransform' => null, 'collapsible' => false])
<div {{ $attributes->merge(['class' => 'block']) }}>
    <div @if ($collapsible) @click="open = !open" class="flex cursor-pointer items-center justify-between px-1 pb-2.5 pt-1.5" @else class="flex items-center justify-between px-1 pb-2.5 pt-1.5" @endif>
        <div class="flex items-center gap-2.5">
            @if ($icon)
                <span class="flex h-7 w-7 items-center justify-center monitor-glass-pill rounded-md border text-neutral-500 dark:text-neutral-400">
                    <x-monitor::icon :path="$icon" :view-box="$iconViewBox" :fill="$iconFill" :transform="$iconTransform"/>
                </span>
            @endif
            @if ($title)
                <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $title }}</h2>
            @endif
        </div>
        {{ $actions ?? '' }}
    </div>
    {{ $slot }}
</div>
