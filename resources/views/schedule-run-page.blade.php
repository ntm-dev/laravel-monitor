{{-- Standalone Scheduled Task Run Detail page (route: monitor.schedule.runs.show).
     Unlike the tab-based dashboard views, this page owns its own URL and
     fetches everything it needs itself — see
     Http\Controllers\ScheduleRunController. Mirrors job-attempt-page.blade.php/
     command-run-page.blade.php: a simple status header, the event summary
     and the shared waterfall timeline, with no HTTP-specific sections. --}}
@php
    use LaravelMonitor\Support\Format;

    $command = $root->key ?? 'Scheduled Task';
    $status = $root->subtype ?? 'finished';
    $badgeClass = match ($status) {
        'failed' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        'skipped' => 'bg-neutral-200/70 text-neutral-600 dark:bg-neutral-500/10 dark:text-neutral-400',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
    };
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
                        <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $badgeClass }}">{{ $status }}</span>
                        <h1 class="min-w-0 truncate font-mono text-2xl font-bold tracking-tight" title="{{ $command }}">{{ $command }}</h1>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1600px] flex-1 space-y-4 px-4 pb-10 md:px-8">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <x-monitor::card class="p-3">
                        <p class="font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.schedule.expression') }}</p>
                        <p class="mt-1.5 truncate font-mono text-xl font-semibold text-neutral-900 dark:text-neutral-100" title="{{ $root->payload['expression'] ?? '' }}">{{ $root->payload['expression'] ?? '—' }}</p>
                    </x-monitor::card>
                    <x-monitor::card class="p-3">
                        <p class="font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.settings.timezone') }}</p>
                        <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $root->payload['timezone'] ?? '—' }}</p>
                    </x-monitor::card>
                    <x-monitor::card class="p-3">
                        <p class="font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</p>
                        <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $root->duration !== null ? Format::duration($root->duration) : '—' }}</p>
                    </x-monitor::card>
                    <x-monitor::card class="p-3">
                        <p class="font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.ran_at') }}</p>
                        <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ Format::datetime($root->created_at) }}</p>
                    </x-monitor::card>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($root->payload['without_overlapping'] ?? false)
                        <span class="rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 font-mono text-[10px] uppercase leading-tight text-amber-600 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">{{ __('monitor::messages.schedule.without_overlapping') }}</span>
                    @endif
                    @if ($root->payload['run_in_background'] ?? false)
                        <span class="rounded border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 font-mono text-[10px] uppercase leading-tight text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">{{ __('monitor::messages.schedule.runs_in_background') }}</span>
                    @endif
                    @if ($root->payload['even_in_maintenance_mode'] ?? false)
                        <span class="rounded border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 font-mono text-[10px] uppercase leading-tight text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">{{ __('monitor::messages.schedule.runs_in_maintenance_mode') }}</span>
                    @endif
                </div>

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
