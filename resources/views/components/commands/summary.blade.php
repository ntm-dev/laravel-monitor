{{-- CommandSummary: General info (exit code/started at/duration/peak
     memory/models loaded/server) for a single command run — the
     command-run-page's equivalent of requests/summary.blade.php and
     jobs/summary.blade.php. --}}
@props(['root', 'scheduledTask' => null])
@php
    $payload = $root->payload ?? [];

    $startedAt = \LaravelMonitor\Support\Format::startedAt($root);

    $general = array_filter([
        'exit_code' => $payload['exit_code'] ?? '—',
        // The full command line as invoked, arguments included — only worth
        // a row of its own when it says more than the `key` already on the
        // page heading (see Recorders\Commands::commandLine()).
        'command' => $payload['command'] ?? null,
        'started_at' => $startedAt !== null ? \LaravelMonitor\Support\Format::datetime($startedAt) : null,
        'duration' => \LaravelMonitor\Support\Format::duration($root->duration),
        'peak_memory' => \LaravelMonitor\Support\Number::fileSize($payload['peak_memory'] ?? null),
        'model_count' => isset($payload['model_count']) ? number_format($payload['model_count']) : null,
        'server' => $payload['server'] ?? null,
    ], fn ($value) => $value !== null);

    $generalLabels = [
        'exit_code' => __('monitor::messages.common.exit_code'),
        'command' => __('monitor::messages.common.command'),
        'started_at' => __('monitor::messages.command.started_at'),
        'duration' => __('monitor::messages.common.duration'),
        'peak_memory' => __('monitor::messages.common.peak_memory'),
        'model_count' => __('monitor::messages.common.models_loaded'),
        'server' => __('monitor::messages.common.server'),
    ];
@endphp
<x-monitor::card class="p-4">
    <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.general') }}</h2>
    <dl class="space-y-2 text-sm">
        @foreach ($general as $key => $value)
            <div class="flex items-baseline justify-between gap-3">
                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $generalLabels[$key] }}</dt>
                <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                @if ($key === 'command')
                    <dd class="min-w-0 shrink truncate font-mono text-xs text-neutral-800 dark:text-neutral-200" title="{{ $value }}">{{ $value }}</dd>
                @else
                    <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                @endif
            </div>
        @endforeach

        {{-- start row scheduled task origin --}}
        @if ($scheduledTask !== null)
            <div class="flex items-baseline justify-between gap-3">
                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.command.scheduled_task') }}</dt>
                <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                <dd class="min-w-0 shrink font-mono text-xs">
                    <a href="{{ route('monitor.schedule.runs.show', $scheduledTask->request_id) }}"
                       class="truncate text-blue-600 hover:underline dark:text-purple-400">{{ $scheduledTask->key }}</a>
                </dd>
            </div>
        @endif
        {{-- end row scheduled task origin --}}
    </dl>
</x-monitor::card>
