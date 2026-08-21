@php
    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $tz = \LaravelMonitor\Support\Format::timezone();
    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2"
         x-data="{
             hoverIndex: null,
             setHoverIndex(i) { this.hoverIndex = i },
             clearHoverIndex() { this.hoverIndex = null },
         }">
        <x-monitor::jobs-chart-card :label="__('monitor::messages.common.attempts')"
            :processed="$processed" :failed="$failed" :released="$released"
            :processed-buckets="$processedBuckets" :failed-buckets="$failedBuckets" :released-buckets="$releasedBuckets"
            :since="$since" :until="$until" height="h-[167px]"/>
        <x-monitor::duration-chart-card :label="__('monitor::messages.common.job_duration')" :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- Individual job runs --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::JOBS" class="h-4 w-4 text-blue-600 dark:text-purple-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalEntries) }} {{ trans_choice('monitor::messages.common.job_run_count', $totalEntries) }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_job_runs_recorded_in_period') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.date') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.queue') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.status') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.duration') }}</th>
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                        @foreach ($entries as $entry)
                            {{-- Only processed/failed/released rows carry an attempt id of their
                                 own (minted at JobProcessing via beginJobAttempt(), see
                                 Recorders\Jobs) — a 'queued' row's request_id instead points to
                                 whatever dispatched the job, so it wouldn't resolve here. --}}
                            @php($attemptUrl = ($entry->request_id ?? null) && $entry->subtype !== 'queued' ? route('monitor.jobs.attempts.show', $entry->request_id) : null)
                            <tr class="{{ $attemptUrl ? 'group cursor-pointer' : '' }} rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset"
                                @if ($attemptUrl) onclick="window.location='{{ $attemptUrl }}'" @endif>
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ \LaravelMonitor\Support\Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $entry->payload['queue'] ?? 'default' }}</td>
                                <td class="py-2 pr-3">
                                    <span @class([
                                        'rounded px-1.5 py-0.5 font-mono text-[10px] uppercase bg-neutral-200 dark:bg-neutral-800 shadow-neu-inset dark:shadow-neu-dark-inset',
                                        'text-emerald-600 dark:text-emerald-400' => $entry->subtype === 'processed',
                                        'text-amber-600 dark:text-amber-400' => $entry->subtype === 'queued',
                                        'text-orange-600 dark:text-orange-400' => $entry->subtype === 'released',
                                        'text-rose-600 dark:text-rose-400' => $entry->subtype === 'failed',
                                    ])>{{ $entry->subtype }}</span>
                                    @if (($entry->payload['attempts'] ?? null) !== null)
                                        <span class="ml-1 font-mono text-[10px] text-neutral-400 dark:text-neutral-500" title="{{ __('monitor::messages.common.attempt_count') }}">#{{ $entry->payload['attempts'] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($entry->duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    @if ($attemptUrl)
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg text-neutral-300 dark:text-neutral-600 group-hover:bg-neutral-200 dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-neu-sm dark:group-hover:shadow-neu-dark-sm">
                                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                        <x-monitor::table-skeleton :columns="5" :rows="count($entries)"/>
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalEntries), 'total' => number_format($totalEntries)])"/>
                @endif
            @endif
        </x-monitor::card>
    </div>
</div>
