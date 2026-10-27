{{-- General info and User info cards for a request, side by side. --}}
@props(['root', 'userName', 'timezone'])
@php
    $payload = $root->payload ?? [];

    // created_at is the request's end; 'date' shows the start (created_at - duration).
    // Float epoch math keeps the sub-ms part Carbon's sub*() would drop.
    $endEpoch = (float) $root->created_at->format('U.u');
    $startEpoch = $endEpoch - ((float) $root->duration / 1000);

    $preciseTimestamp = fn (float $epoch) => \LaravelMonitor\Support\Format::datetime(
        \Carbon\CarbonImmutable::createFromFormat('U.u', number_format($epoch, 6, '.', '')),
        \LaravelMonitor\Support\Format::DATETIME_PRECISE,
    ).' '.$timezone;

    $endTime = $preciseTimestamp($endEpoch);

    $general = array_filter([
        'date' => $preciseTimestamp($startEpoch),
        'duration' => \LaravelMonitor\Support\Format::duration($root->duration),
        'status_code' => $payload['response']['status'] ?? '—',
        'route' => $payload['request']['route_name'] ?? null,
        'action' => $payload['request']['route_action'] ?? null,
        'domain' => $payload['request']['route_domain'] ?? null,
        'server' => $payload['server'] ?? '—',
        'response_size' => \LaravelMonitor\Support\Number::fileSize($payload['response']['size'] ?? null, '—'),
        'peak_memory' => \LaravelMonitor\Support\Number::fileSize($payload['peak_memory'] ?? null, '—'),
        'model_count' => isset($payload['model_count']) ? number_format($payload['model_count']) : null,
    ], fn ($value) => $value !== null);

    $generalLabels = [
        'date' => __('monitor::messages.common.date'),
        'duration' => __('monitor::messages.common.duration'),
        'status_code' => __('monitor::messages.common.status_code'),
        'route' => __('monitor::messages.common.route'),
        'action' => __('monitor::messages.common.action'),
        'domain' => __('monitor::messages.common.domain'),
        'server' => __('monitor::messages.common.server'),
        'response_size' => __('monitor::messages.common.response_size'),
        'peak_memory' => __('monitor::messages.common.peak_memory'),
        'model_count' => __('monitor::messages.common.models_loaded'),
    ];

    $user = [
        'user' => $userName ?? ($root->user_id !== null ? __('monitor::messages.common.user_number', ['id' => $root->user_id]) : __('monitor::messages.common.guest')),
        'ip' => $payload['request']['ip'] ?? '—',
    ];

    $userLabels = [
        'user' => __('monitor::messages.common.user'),
        'ip' => __('monitor::messages.common.ip_address'),
    ];
@endphp
<x-monitor::card class="p-4">
    <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.general') }}</h2>
    <dl class="space-y-2 text-sm">
        @foreach ($general as $key => $value)
            <div class="flex items-baseline justify-between gap-3">
                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $generalLabels[$key] }}</dt>
                <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                @if ($key === 'status_code' && is_numeric($value))
                    <dd class="flex shrink-0 items-center gap-2">
                        <span class="rounded px-1.5 py-0.5 font-mono text-xs {{ \LaravelMonitor\Support\Format::statusBadgeClass((int) $value) }}">{{ $value }}</span>
                        @if ($statusText = \LaravelMonitor\Support\Format::statusText((int) $value))
                            <span class="font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $statusText }}</span>
                        @endif
                    </dd>
                @elseif ($key === 'duration')
                    {{-- Tooltip: the precise end time ('date' shows the start). --}}
                    <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200" data-tooltip="{{ $endTime }}">{{ $value }}</dd>
                @else
                    <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                @endif
            </div>
        @endforeach
    </dl>
    <h2 class="mb-3 mt-5 font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.user') }}</h2>
    <dl class="space-y-2 text-sm">
        @foreach ($user as $key => $value)
            <div class="flex items-baseline justify-between gap-3">
                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $userLabels[$key] }}</dt>
                <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</x-monitor::card>
