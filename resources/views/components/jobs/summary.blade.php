{{-- JobSummary: General info (status/queue/connection/duration/ran at) for a
     single job execution — the request-detail-page's equivalent of
     requests/summary.blade.php, shown instead of it when the page was
     reached via a job's own <request_url>/<job_id> link (see
     Http\Controllers\RequestDetailController). --}}
@props(['root'])
@php
    $payload = $root->payload ?? [];
    $status = $root->subtype ?? 'processed';
    $badgeClass = match ($status) {
        'failed' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
    };

    $general = array_filter([
        'Job' => $root->key ?? null,
        'Status' => $status,
        'Queue' => $payload['queue'] ?? 'default',
        'Connection' => $payload['connection'] ?? '—',
        'Duration' => \LaravelMonitor\Support\Format::duration($root->duration),
        'Ran At' => \LaravelMonitor\Support\Format::datetime($root->created_at),
        'Attempts' => $payload['attempts'] ?? null,
        'Models Loaded' => isset($payload['model_count']) ? number_format($payload['model_count']) : null,
    ], fn ($value) => $value !== null);
@endphp
<x-monitor::card class="p-4">
    <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">General</h2>
    <dl class="space-y-2 text-sm">
        @foreach ($general as $label => $value)
            <div class="flex items-baseline justify-between gap-3">
                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $label }}</dt>
                <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                @if ($label === 'Status')
                    <dd class="shrink-0">
                        <span class="rounded px-1.5 py-0.5 font-mono text-[10px] uppercase {{ $badgeClass }}">{{ $value }}</span>
                    </dd>
                @elseif ($label === 'Job')
                    <dd class="shrink-0 truncate font-mono text-xs text-neutral-800 dark:text-neutral-200" title="{{ $value }}">{{ class_basename($value) }}</dd>
                @else
                    <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                @endif
            </div>
        @endforeach
    </dl>
    @if (($payload['exception'] ?? null) !== null)
        <div class="mt-5">
            <p class="font-mono text-xs uppercase tracking-tight text-rose-600 dark:text-rose-400">{{ $payload['exception'] }}</p>
            <p class="mt-1 text-sm text-neutral-700 dark:text-neutral-200">{{ $payload['message'] ?? '' }}</p>
        </div>
    @endif
</x-monitor::card>
