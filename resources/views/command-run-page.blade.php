{{-- Standalone Command Run Detail page (route: monitor.commands.runs.show).
     Unlike the tab-based dashboard views, this page owns its own URL and
     fetches everything it needs itself — see
     Http\Controllers\CommandRunController. Mirrors job-attempt-page.blade.php:
     a simple status header, the event summary and the shared waterfall
     timeline, with no HTTP-specific sections. --}}
@php
    use LaravelMonitor\Support\KeyHash;

    $command = $root->key ?? 'Command';
    // The command line as invoked, arguments included — falls back to the
    // bare name for runs recorded before it was captured, and for commands
    // invoked without any (see Recorders\Commands::commandLine()).
    $invocation = $root->payload['command'] ?? $command;
    $status = $root->subtype ?? 'success';
    $badgeClass = match ($status) {
        'failed' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
    };
@endphp
<x-monitor::layout :title="$invocation">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="$tab" :range="$range" :refresh="$refresh" :app-initial="$appInitial" :auto-refreshes="false"/>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
                <div class="mx-auto w-full max-w-[1600px] px-4 py-5 md:px-8">
                    {{-- start breadcrumb command run --}}
                    <nav class="flex min-w-0 items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                        <a href="{{ route('monitor.dashboard', ['tab' => 'commands'] + $range) }}"
                           class="shrink-0 hover:text-neutral-900 dark:hover:text-neutral-100">
                            {{ __('monitor::messages.nav.commands') }}
                        </a>
                        <span class="shrink-0">›</span>
                        {{-- The command's *name*, not this run's own invocation: it
                             links back to that command's own list of runs, which is
                             keyed by name (arguments live in the payload). --}}
                        <a href="{{ route('monitor.commands.show', ['hash' => KeyHash::for($command)] + $range) }}"
                           data-tooltip="{{ $command }}"
                           class="min-w-0 truncate font-mono hover:text-neutral-900 dark:hover:text-neutral-100">
                            {{ $command }}
                        </a>
                    </nav>
                    {{-- end breadcrumb command run --}}

                    <div class="mt-1 flex flex-wrap items-center gap-2.5">
                        <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $badgeClass }}">{{ $status }}</span>
                        <h1 class="min-w-0 truncate font-mono text-2xl font-bold tracking-tight" data-tooltip="{{ $invocation }}">{{ $invocation }}</h1>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1600px] flex-1 space-y-4 px-4 pb-10 md:px-8">
                {{-- start card general info --}}
                <x-monitor::commands.summary :root="$root" :scheduled-task="$scheduledTask"/>
                {{-- end card general info --}}

                <x-monitor::requests.event-summary :summary="$summary"/>

                <x-monitor::requests.timeline :tracks="$tracks" :default-track="$defaultTrack" :scroll-to-outcome-id="$scrollToOutcomeId"/>
            </main>
        </div>
    </div>
</x-monitor::layout>
