{{-- Standalone Scheduled Task Run Detail page (route: monitor.schedule.runs.show).
     Unlike the tab-based dashboard views, this page owns its own URL and
     fetches everything it needs itself — see
     Http\Controllers\ScheduleRunController. Mirrors job-attempt-page.blade.php/
     command-run-page.blade.php: a simple status header, the event summary
     and the shared waterfall timeline, with no HTTP-specific sections. --}}
@php
    use LaravelMonitor\Support\Format;

    $command = $root->payload['command'] ?? $root->key ?? 'Scheduled Task';
    $status = $root->subtype ?? 'finished';
    $badgeClass = match ($status) {
        'failed' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        'skipped' => 'bg-neutral-200/70 text-neutral-600 dark:bg-neutral-500/10 dark:text-neutral-400',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
    };

    // Same B/KB/MB/... scaling as requests/summary.blade.php's own $bytes.
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

    $yesNo = fn (bool $value) => __($value ? 'monitor::messages.common.yes' : 'monitor::messages.common.no');

    $general = [
        'date' => Format::datetime($root->created_at).' '.$timezone,
        'status' => __("monitor::messages.common.{$status}"),
        'peak_memory' => $bytes($root->payload['peak_memory'] ?? null),
        'server' => $root->payload['server'] ?? '—',
        'without_overlapping' => $yesNo($root->payload['without_overlapping'] ?? false),
        'on_one_server' => $yesNo($root->payload['on_one_server'] ?? false),
        'run_in_background' => $yesNo($root->payload['run_in_background'] ?? false),
        'even_in_maintenance_mode' => $yesNo($root->payload['even_in_maintenance_mode'] ?? false),
    ];

    $generalLabels = [
        'date' => __('monitor::messages.common.date'),
        'status' => __('monitor::messages.common.status'),
        'peak_memory' => __('monitor::messages.common.peak_memory'),
        'server' => __('monitor::messages.common.server'),
        'without_overlapping' => __('monitor::messages.schedule.without_overlapping'),
        'on_one_server' => __('monitor::messages.schedule.on_one_server'),
        'run_in_background' => __('monitor::messages.schedule.runs_in_background'),
        'even_in_maintenance_mode' => __('monitor::messages.schedule.runs_in_maintenance_mode'),
    ];
@endphp
<x-monitor::layout :title="$command">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="$tab" :range="$range" :refresh="$refresh" :app-initial="$appInitial"/>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
                <div class="mx-auto w-full max-w-[1600px] px-4 py-5 md:px-8">
                    <a href="{{ route('monitor.dashboard', ['tab' => 'schedule'] + $range) }}"
                       class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">
                        ← {{ __('monitor::messages.nav.schedule') }}
                    </a>

                    <div class="mt-1 flex flex-wrap items-center gap-2.5">
                        <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $badgeClass }}">{{ __("monitor::messages.common.{$status}") }}</span>
                        <h1 class="min-w-0 truncate font-mono text-2xl font-bold tracking-tight" title="{{ $command }}">{{ $command }}</h1>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1600px] flex-1 space-y-4 px-4 pb-10 md:px-8">
                {{-- start card general info --}}
                <x-monitor::card class="p-4">
                    <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.general') }}</h2>
                    <dl class="space-y-2 text-sm">
                        @foreach ($general as $key => $value)
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $generalLabels[$key] }}</dt>
                                <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                                @if ($key === 'status')
                                    <dd class="shrink-0">
                                        <span class="rounded px-1.5 py-0.5 font-mono text-xs {{ $badgeClass }}">{{ $value }}</span>
                                    </dd>
                                @else
                                    <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                </x-monitor::card>
                {{-- end card general info --}}

                @if (($root->payload['error'] ?? null) !== null)
                    <x-monitor::card class="p-4">
                        <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ $root->payload['error'] }}</p>
                    </x-monitor::card>
                @endif

                <x-monitor::requests.event-summary :summary="$summary"/>

                <x-monitor::requests.timeline :tracks="$tracks" :default-track="$defaultTrack"/>
            </main>
        </div>
    </div>
</x-monitor::layout>
