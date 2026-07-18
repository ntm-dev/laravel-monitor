{{-- RequestSummary: General info (date/status/server/size/memory) and User
     info (name/id + IP) side by side. --}}
@props(['root', 'userName', 'timezone'])
@php
    $payload = $root->payload ?? [];

    $bytes = function (?int $value): string {
        if ($value === null) {
            return '—';
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
        'Date' => \LaravelMonitor\Support\Format::datetime($root->created_at).' '.$timezone,
        'Status Code' => $payload['status'] ?? '—',
        'Route' => $payload['route_name'] ?? null,
        'Action' => $payload['route_action'] ?? null,
        'Domain' => $payload['route_domain'] ?? null,
        'Server' => $payload['server'] ?? '—',
        'Response Size' => $bytes($payload['response_size'] ?? null),
        'Peak Memory' => $bytes($payload['peak_memory'] ?? null),
        'Models Loaded' => isset($payload['model_count']) ? number_format($payload['model_count']) : null,
    ], fn ($value) => $value !== null);

    $user = [
        'User' => $userName ?? ($root->user_id !== null ? 'User #'.$root->user_id : 'Guest'),
        'IP Address' => $payload['ip'] ?? '—',
    ];
@endphp
<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <x-monitor::card class="p-4">
        <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">General</h2>
        <dl class="space-y-2 text-sm">
            @foreach ($general as $label => $value)
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $label }}</dt>
                    <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                    <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-monitor::card>

    <x-monitor::card class="p-4">
        <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">User</h2>
        <dl class="space-y-2 text-sm">
            @foreach ($user as $label => $value)
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $label }}</dt>
                    <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                    <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-monitor::card>
</div>
