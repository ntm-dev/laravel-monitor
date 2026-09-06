@php($fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms))
<div data-monitor-poll>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::APPLICATION" :title="__('monitor::messages.common.application')">
        <x-slot:actions>
            <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'jobs'] + $range)" external>{{ __('monitor::messages.nav.jobs') }}</x-monitor::link-button>
        </x-slot:actions>

        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-3">
            {{-- Exceptions --}}
            @if ($exceptions > 0)
                <x-monitor::card class="flex flex-col p-4">
                    <x-monitor::badge>{{ __('monitor::messages.nav.exceptions') }}</x-monitor::badge>
                    <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900 dark:text-neutral-100">{{ number_format($exceptions) }} {{ __('monitor::messages.common.exceptions_reported_phrase') }} {{ $periodPhrase }}.</p>
                    <p class="mt-1.5 text-sm text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.errors_have_impacted') }} {{ $impactedUsers }} {{ trans_choice('monitor::messages.common.user_count', $impactedUsers) }}.</p>
                    <div class="mt-6 flex-1"
                         x-data="{
                             hoverIndex: null,
                             setHoverIndex(i) { this.hoverIndex = i },
                             clearHoverIndex() { this.hoverIndex = null },
                         }">
                        <x-monitor::bar-chart :since="$since" :until="$until" height="h-36"
                            :series="[['label' => __('monitor::messages.common.unhandled'), 'dot' => 'bg-rose-500', 'data' => $exceptionBuckets]]"/>
                    </div>
                    <div class="mt-3 flex items-center justify-center gap-4 font-mono text-[11px] text-neutral-500 dark:text-neutral-400">
                        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-1 rounded-full bg-neutral-300 dark:bg-neutral-600"></span>0 {{ __('monitor::messages.common.handled') }}</span>
                        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-1 rounded-full bg-rose-500"></span>{{ number_format($exceptions) }} {{ __('monitor::messages.common.unhandled') }}</span>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'exceptions'] + $range)">{{ __('monitor::messages.common.view') }}</x-monitor::link-button>
                    </div>
                </x-monitor::card>
            @else
                <x-monitor::empty-state :label="__('monitor::messages.nav.exceptions')" :message="__('monitor::messages.common.no_exceptions_reported')" :period-phrase="$periodPhrase"/>
            @endif

            {{-- Routes over threshold --}}
            @if ($slowRouteCount > 0)
                <x-monitor::card class="flex flex-col p-4">
                    <x-monitor::badge>{{ __('monitor::messages.common.routes') }}</x-monitor::badge>
                    <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900 dark:text-neutral-100">{{ number_format($slowRouteCount) }} {{ trans_choice('monitor::messages.common.route_count', $slowRouteCount) }} {{ __('monitor::messages.common.exceeded_thresholds') }} {{ $periodPhrase }}.</p>
                    <div class="mt-5 relative{{ $slowRouteCount > 3 ? ' pb-2' : '' }}">
                        @if ($slowRouteCount > 3)
                            {{-- start stacked-card peek effect: one card tucked behind the last route row, hinting at more hidden below it --}}
                            <div class="absolute inset-x-2 bottom-0 h-2 rounded-b-lg border border-neutral-200 dark:border-neutral-700/70 bg-neutral-100 dark:bg-neutral-800/60"></div>
                            {{-- end stacked-card peek effect: one card tucked behind the last route row, hinting at more hidden below it --}}
                        @endif
                        <div class="relative z-10 space-y-2">
                            @foreach ($slowRoutes as $route)
                                <a href="{{ route('monitor.requests.routes.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($route->key)] + $range) }}"
                                   class="flex items-center justify-between gap-3 rounded-lg border
                                   border-neutral-200 dark:border-neutral-700 bg-white
                                   dark:bg-neutral-800/50 p-3 transition-transform duration-200
                                   ease-out hover:scale-105 hover:border-neutral-300
                                   dark:hover:border-neutral-700 hover:bg-white
                                   hover:shadow-xl shadow-md shadow-black/5 dark:shadow-white/5"
                                >
                                    <span class="min-w-0">
                                        <span class="block font-mono text-[11px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">{{ \Illuminate\Support\Str::before($route->key, ' ') }}</span>
                                        <span class="block truncate font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ \Illuminate\Support\Str::after($route->key, ' ') }}</span>
                                    </span>
                                    <span class="shrink-0 font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.max_abbr') }} <span class="text-amber-600 dark:text-amber-400">{{ $fmt($route->max_duration) }}</span></span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                    <div class="mt-auto flex justify-end pt-4">
                        <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'requests'] + $range)">{{ __('monitor::messages.common.view') }}</x-monitor::link-button>
                    </div>
                </x-monitor::card>
            @else
                <x-monitor::empty-state :label="__('monitor::messages.common.routes')" :message="__('monitor::messages.common.no_routes_exceeded_thresholds')" :period-phrase="$periodPhrase"/>
            @endif

            {{-- Jobs --}}
            <div class="flex flex-col gap-1.5"
                 x-data="{
                     hoverIndex: null,
                     setHoverIndex(i) { this.hoverIndex = i },
                     clearHoverIndex() { this.hoverIndex = null },
                 }">
                <x-monitor::jobs-chart-card class="flex-1"
                    :queued="$queuedJobs" :processed="$processedJobs" :failed="$failedJobs"
                    :queued-buckets="$queuedBuckets" :processed-buckets="$processedBuckets" :failed-buckets="$failedBuckets"
                    :since="$since" :until="$until" size="sm" height="h-24" :footer="false"/>
                <x-monitor::duration-chart-card class="flex-1" :label="__('monitor::messages.common.job_duration')" :duration="$jobDuration"
                    :since="$since" :until="$until" size="sm" height="h-24" :footer="false"/>
            </div>
        </div>
    </x-monitor::section>
</div>
