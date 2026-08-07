{{-- RequestHeader: back link, then either the request's own method + status
     badges/path/URL, or -- once a job is the page's active entity (see
     $job) -- that job's own status badge and class name instead, same swap
     job-attempt-page.blade.php's standalone header makes (a job has neither
     an HTTP status, a path, nor a URL). --}}
@props(['root', 'range', 'job' => null, 'breadcrumbTab' => 'requests', 'breadcrumbLabel' => null, 'breadcrumbUrl' => null])
@php
    use Illuminate\Support\Str;

    $method = $root->payload['method'] ?? Str::before($root->key ?? '', ' ');
    $path = $root->payload['path'] ?? Str::after($root->key ?? '', ' ');
    $url = $root->payload['url'] ?? null;
    $status = (int) ($root->payload['status'] ?? 0);
    $badgeClass = \LaravelMonitor\Support\Format::statusBadgeClass($status);

    $jobClass = $job->key ?? null;
    $jobStatus = $job->subtype ?? 'processed';
    $jobBadgeClass = match ($jobStatus) {
        'failed' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
    };
@endphp
<header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
    <div class="mx-auto w-full max-w-[calc(100%-10px)] px-4 py-5 md:px-8">
        <nav class="flex min-w-0 items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
            <a href="{{ route('monitor.dashboard', ['tab' => $breadcrumbTab] + $range) }}"
               class="shrink-0 hover:text-neutral-900 dark:hover:text-neutral-100">
                {{ __('monitor::messages.nav.'.$breadcrumbTab) }}
            </a>
            @if ($breadcrumbLabel !== null)
                <span class="shrink-0">›</span>
                <a href="{{ $breadcrumbUrl }}" title="{{ $breadcrumbLabel }}"
                   class="min-w-0 truncate font-mono hover:text-neutral-900 dark:hover:text-neutral-100">
                    {{ $breadcrumbLabel }}
                </a>
            @endif
        </nav>

        @if ($job !== null)
            <div class="mt-1 flex flex-wrap items-center gap-2.5">
                <h1 class="min-w-0 truncate text-2xl font-bold tracking-tight" title="{{ $jobClass }}">{{ $jobClass }}</h1>
            </div>
        @else
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
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="copied" x-cloak
                            x-transition:enter="transition-[clip-path] ease-out duration-1000" x-transition:enter-start="[clip-path:inset(0_100%_0_0)]" x-transition:enter-end="[clip-path:inset(0_0_0_0)]"/>
                    </button>
                </div>
            @endif
        @endif
    </div>
</header>
