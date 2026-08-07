{{-- JobSummary: General info (status/queued/end time/connection/queue/peak
     memory/server) for a single job execution — the request-detail-page's
     equivalent of requests/summary.blade.php, shown instead of it when the
     page was reached via a job's own <request_url>/<job_id> link (see
     Http\Controllers\RequestDetailController). --}}
@props(['root', 'queuedAt' => null])
@php
    $payload = $root->payload ?? [];
    $status = $root->subtype ?? 'processed';
    $badgeClass = match ($status) {
        'failed' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
    };

    // Same B/KB/MB/... scaling as requests/summary.blade.php's own $bytes —
    // duplicated rather than shared since it's a 4-line, view-local formatter.
    $bytes = function (?int $value): ?string {
        if ($value === null) {
            return null;
        }

        if ($value < 1024) {
            return $value.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $scaled = $value;

        foreach ($units as $unit) {
            $scaled /= 1024;

            if ($scaled < 1024) {
                return number_format($scaled, 1).' '.$unit;
            }
        }

        return number_format($scaled, 1).' TB';
    };

    $general = array_filter([
        'status' => $status,
        'queued_at' => $queuedAt !== null ? \LaravelMonitor\Support\Format::datetime($queuedAt) : null,
        'end_time' => \LaravelMonitor\Support\Format::datetime($root->created_at),
        'connection' => $payload['connection'] ?? '—',
        'queue' => $payload['queue'] ?? 'default',
        'peak_memory' => $bytes($payload['peak_memory'] ?? null),
        'server' => $payload['server'] ?? null,
    ], fn ($value) => $value !== null);

    $generalLabels = [
        'status' => __('monitor::messages.common.status'),
        'queued_at' => __('monitor::messages.job.queued_at'),
        'end_time' => __('monitor::messages.job.end_time'),
        'connection' => __('monitor::messages.common.connection'),
        'queue' => __('monitor::messages.common.queue'),
        'peak_memory' => __('monitor::messages.common.peak_memory'),
        'server' => __('monitor::messages.common.server'),
    ];
@endphp
<x-monitor::card class="p-4">
    <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.general') }}</h2>
    <dl class="space-y-2 text-sm">
        @foreach ($general as $key => $value)
            <div class="flex items-baseline justify-between gap-3">
                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $generalLabels[$key] }}</dt>
                <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                @if ($key === 'status')
                    <dd class="shrink-0">
                        <span class="rounded px-1.5 py-0.5 font-mono text-[10px] uppercase {{ $badgeClass }}">{{ $value }}</span>
                    </dd>
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
