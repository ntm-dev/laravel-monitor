{{-- Section frame: icon chip + title header, optional actions slot. --}}
@props(['icon' => null, 'title' => null, 'iconViewBox' => '0 0 24 24', 'iconFill' => 'none', 'iconTransform' => null, 'collapsible' => false])
<div {{ $attributes->merge(['class' => 'rounded-xl bg-neutral-200 p-1.5 shadow-neu-inset dark:bg-neutral-800 dark:shadow-neu-dark-inset']) }}>
    <div @if ($collapsible) @click="open = !open" class="flex cursor-pointer items-center justify-between px-1 pb-2.5 pt-1.5" @else class="flex items-center justify-between px-1 pb-2.5 pt-1.5" @endif>
        <div class="flex items-center gap-2.5">
            @if ($icon)
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-neutral-200 text-neutral-500 shadow-neu-sm dark:bg-neutral-800 dark:text-neutral-400 dark:shadow-neu-dark-sm">
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
