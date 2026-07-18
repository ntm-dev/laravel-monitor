{{-- Standalone Command Run Detail page (route: monitor.commands.runs.show).
     Unlike the tab-based dashboard views, this page owns its own URL and
     fetches everything it needs itself — see
     Http\Controllers\CommandRunController. Mirrors job-attempt-page.blade.php:
     a simple status header, the event summary and the shared waterfall
     timeline, with no HTTP-specific sections. --}}
@php
    use LaravelMonitor\Support\Format;

    $command = $root->key ?? 'Command';
    $status = $root->subtype ?? 'success';
    $badgeClass = match ($status) {
        'failed' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
    };
@endphp
<x-monitor::layout :title="$command">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="$tab" :range="$range" :refresh="$refresh" :app-initial="$appInitial"/>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
                <div class="mx-auto w-full max-w-[1600px] px-4 py-5 md:px-8">
                    <a href="{{ route('monitor.dashboard', ['tab' => 'commands'] + $range) }}"
                       class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">
                        ← Commands
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
                        <p class="font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Exit Code</p>
                        <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $root->payload['exit_code'] ?? '—' }}</p>
                    </x-monitor::card>
                    <x-monitor::card class="p-3">
                        <p class="font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Duration</p>
                        <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ Format::duration($root->duration) }}</p>
                    </x-monitor::card>
                    <x-monitor::card class="p-3">
                        <p class="font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Ran at</p>
                        <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ Format::datetime($root->created_at) }}</p>
                    </x-monitor::card>
                    <x-monitor::card class="p-3">
                        <p class="font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Models Loaded</p>
                        <p class="mt-1.5 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($root->payload['model_count'] ?? 0) }}</p>
                    </x-monitor::card>
                </div>

                <x-monitor::requests.event-summary :summary="$summary"/>

                <x-monitor::requests.timeline :entries="$timeline" :total-duration="$totalDuration" root-label="COMMAND"/>
            </main>
        </div>
    </div>
</x-monitor::layout>
