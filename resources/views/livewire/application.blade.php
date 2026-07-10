@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::APPLICATION" title="Application">
        <x-slot:actions>
            <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'jobs'] + $range)" external>Jobs</x-monitor::link-button>
        </x-slot:actions>

        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-3">
            {{-- Exceptions --}}
            @if ($exceptions > 0)
                <x-monitor::card class="flex flex-col p-4">
                    <x-monitor::badge>Exceptions</x-monitor::badge>
                    <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900">{{ number_format($exceptions) }} exceptions reported {{ $periodPhrase }}.</p>
                    <p class="mt-1.5 text-sm text-neutral-500">Errors have impacted {{ $impactedUsers }} {{ $impactedUsers === 1 ? 'user' : 'users' }}.</p>
                    <div class="mt-6 flex-1">
                        <x-monitor::bar-chart :since="$since" :until="$until" height="h-36"
                            :series="[['label' => 'Unhandled', 'dot' => 'bg-rose-500', 'data' => $exceptionBuckets]]"/>
                    </div>
                    <div class="mt-3 flex items-center justify-center gap-4 font-mono text-[11px] text-neutral-500">
                        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-1 rounded-full bg-neutral-300"></span>0 Handled</span>
                        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-1 rounded-full bg-rose-500"></span>{{ number_format($exceptions) }} Unhandled</span>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'exceptions'] + $range)">View</x-monitor::link-button>
                    </div>
                </x-monitor::card>
            @else
                <x-monitor::empty-state label="Exceptions" message="No exceptions reported" :period-phrase="$periodPhrase"/>
            @endif

            {{-- Routes over threshold --}}
            @if ($slowRouteCount > 0)
                <x-monitor::card class="flex flex-col p-4">
                    <x-monitor::badge>Routes</x-monitor::badge>
                    <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900">{{ number_format($slowRouteCount) }} {{ $slowRouteCount === 1 ? 'route' : 'routes' }} exceeded thresholds {{ $periodPhrase }}.</p>
                    <div class="mt-5 space-y-2">
                        @foreach ($slowRoutes as $route)
                            <a href="{{ route('monitor.dashboard', ['tab' => 'requests', 'key' => $route->key] + $range) }}"
                               class="flex items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-neutral-50/80 p-3 hover:border-neutral-300 hover:bg-white hover:shadow-sm">
                                <span class="min-w-0">
                                    <span class="block font-mono text-[11px] uppercase tracking-tight text-neutral-400">{{ \Illuminate\Support\Str::before($route->key, ' ') }}</span>
                                    <span class="block truncate font-mono text-xs text-neutral-700">{{ \Illuminate\Support\Str::after($route->key, ' ') }}</span>
                                </span>
                                <span class="shrink-0 font-mono text-xs text-neutral-400">MAX <span class="text-amber-600">{{ $fmt($route->max_duration) }}</span></span>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-auto flex justify-end pt-4">
                        <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'requests'] + $range)">View</x-monitor::link-button>
                    </div>
                </x-monitor::card>
            @else
                <x-monitor::empty-state label="Routes" message="No routes exceeded thresholds" :period-phrase="$periodPhrase"/>
            @endif

            {{-- Jobs --}}
            <div class="flex flex-col gap-1.5">
                <x-monitor::jobs-chart-card class="flex-1"
                    :queued="$queuedJobs" :processed="$processedJobs" :failed="$failedJobs"
                    :queued-buckets="$queuedBuckets" :processed-buckets="$processedBuckets" :failed-buckets="$failedBuckets"
                    :since="$since" :until="$until" size="sm" height="h-24" :footer="false"/>
                <x-monitor::duration-chart-card class="flex-1" label="Job duration" :duration="$jobDuration"
                    :since="$since" :until="$until" size="sm" height="h-24" :footer="false"/>
            </div>
        </div>
    </x-monitor::section>
</div>
