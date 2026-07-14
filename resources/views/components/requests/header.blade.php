{{-- RequestHeader: back link, method + status badges, path and full URL
     with a copy button. --}}
@props(['root', 'range'])
@php
    use Illuminate\Support\Str;

    $method = $root->payload['method'] ?? Str::before($root->key ?? '', ' ');
    $path = $root->payload['path'] ?? Str::after($root->key ?? '', ' ');
    $url = $root->payload['url'] ?? null;
    $status = (int) ($root->payload['status'] ?? 0);
    $badgeClass = match (true) {
        $status >= 500 => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        $status >= 400 => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
    };
@endphp
<header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
    <div class="mx-auto w-full max-w-[1600px] px-4 py-5 md:px-8">
        <a href="{{ route('monitor.dashboard', ['tab' => 'requests'] + $range) }}"
           class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">
            ← Requests
        </a>

        <div class="mt-1 flex flex-wrap items-center gap-2.5">
            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $badgeClass }}">{{ $method }}</span>
            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs {{ $badgeClass }}">{{ $status ?: '—' }}</span>
            <h1 class="min-w-0 truncate text-2xl font-bold tracking-tight" title="{{ $path }}">{{ $path }}</h1>
        </div>

        @if ($url)
            <div class="mt-1 flex items-center gap-1.5" x-data="{ copied: false }">
                <p class="truncate font-mono text-xs text-neutral-500 dark:text-neutral-400" title="{{ $url }}">{{ $url }}</p>
                <button type="button"
                        @click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 1500)"
                        class="shrink-0 text-neutral-400 hover:text-neutral-700 dark:text-neutral-500 dark:hover:text-neutral-200">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5" x-show="! copied"/>
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="copied" x-cloak/>
                </button>
            </div>
        @endif
    </div>
</header>
