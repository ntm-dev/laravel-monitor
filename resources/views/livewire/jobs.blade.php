@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\KeyHash;

    $fmt = fn ($ms) => Format::duration($ms);

    $columns = [
        'key' => ['label' => __('monitor::messages.common.job'), 'align' => 'left'],
        'total' => ['label' => __('monitor::messages.common.total'), 'align' => 'right', 'tooltip' => __('monitor::messages.common.total_hint')],
        'dispatched' => ['label' => __('monitor::messages.common.dispatched'), 'align' => 'right', 'tooltip' => __('monitor::messages.common.dispatched_hint')],
        'pending' => ['label' => __('monitor::messages.common.pending'), 'align' => 'right', 'tooltip' => __('monitor::messages.common.pending_hint')],
        'processing' => ['label' => __('monitor::messages.common.processing'), 'align' => 'right', 'tooltip' => __('monitor::messages.common.processing_hint')],
        'processed' => ['label' => __('monitor::messages.common.processed'), 'align' => 'right', 'tooltip' => __('monitor::messages.common.processed_hint')],
        'released' => ['label' => __('monitor::messages.common.released'), 'align' => 'right', 'tooltip' => __('monitor::messages.common.released_hint')],
        'failed' => ['label' => __('monitor::messages.common.failed'), 'align' => 'right', 'tooltip' => __('monitor::messages.common.failed_hint')],
        'avg_duration' => ['label' => __('monitor::messages.common.avg'), 'align' => 'right'],
        'p95_duration' => ['label' => __('monitor::messages.common.p95'), 'align' => 'right'],
        'last_seen' => ['label' => __('monitor::messages.common.last_seen'), 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
    $tz = Format::timezone();
@endphp
<div data-monitor-poll>
    <x-monitor::section>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-monitor::user-filter :users="$users"/>
                <x-monitor::refresh-button/>
            </div>
        </x-slot:actions>

        {{-- Overview charts --}}
        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
             x-data="{
                 hoverIndex: null,
                 setHoverIndex(i) { this.hoverIndex = i },
                 clearHoverIndex() { this.hoverIndex = null },
             }">
            <x-monitor::jobs-chart-card :label="__('monitor::messages.common.attempts')"
                :processed="$processed" :failed="$failed" :released="$released"
                :processed-buckets="$processedBuckets" :failed-buckets="$failedBuckets" :released-buckets="$releasedBuckets"
                :since="$since" :until="$until" height="h-[167px]"/>
            <x-monitor::duration-chart-card :label="__('monitor::messages.common.job_duration')" :duration="$duration" :since="$since" :until="$until" :threshold="$threshold" height="h-[167px]"/>
        </div>

        {{-- Job table --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalJobs) }} {{ trans_choice('monitor::messages.common.job_count', $totalJobs) }}</h3>
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('monitor::messages.common.search_jobs') }}"
                       class="h-8 w-56 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
            </div>
        </div>

        @if ($jobs->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.jobs')" :message="__('monitor::messages.common.no_jobs_recorded')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            @foreach ($columns as $field => $column)
                                <th class="cursor-pointer select-none pb-2 font-normal {{ $column['align'] === 'right' ? 'text-right' : 'text-left' }}"
                                    wire:click="sort('{{ $field }}')"
                                    @if (! empty($column['tooltip'])) data-tooltip="{{ $column['tooltip'] }}" @endif>
                                    <span class="inline-flex items-center gap-1 {{ $column['align'] === 'right' ? 'flex-row' : '' }}">
                                        {{ $column['label'] }}
                                        <div class=" -right-3 flex flex-col gap-[2px]">
                                            <div
                                                class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px] border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden {{ $sortBy === $field && $sortDirection === 'asc' ? 'border-b-blue-500' : '' }}"
                                            >
                                            </div>
                                            <div
                                                class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px] border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden {{ $sortBy === $field && $sortDirection !== 'asc' ? 'border-b-blue-500' : '' }} rotate-180"
                                            >
                                            </div>
                                        </div>
                                    </span>
                                </th>
                            @endforeach
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="hidden" wire:target.except="$refresh" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($jobs as $job)
                            <tr class="group cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                onclick="window.location='{{ route('monitor.jobs.show', ['hash' => KeyHash::for($job->key)] + $range) }}'">
                                <td class="max-w-[24rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" data-tooltip="{{ $job->key }}">{{ $job->key }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ number_format($job->total) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($job->dispatched) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $job->pending > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($job->pending) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $job->processing > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($job->processing) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-emerald-600 dark:text-emerald-400">{{ number_format($job->processed) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $job->released > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($job->released) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $job->failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($job->failed) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($job->avg_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($job->avg_duration) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($job->p95_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($job->p95_duration) }}</td>
                                <td class="whitespace-nowrap py-2 pl-2 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" data-tooltip="{{ Format::datetime($job->last_seen) }} {{ $tz }}">
                                    <x-monitor::relative-time :at="$job->last_seen"/>
                                </td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-emerald-600 dark:group-hover:text-emerald-300 group-hover:shadow-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tbody wire:loading.class.remove="hidden" wire:target.except="$refresh" class="hidden divide-y divide-neutral-100 dark:divide-neutral-800">
                        <x-monitor::table-skeleton :columns="12" :rows="count($jobs)"/>
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <x-monitor::pagination :page="$page" :last-page="$lastPage"
                        :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalJobs), 'total' => number_format($totalJobs)])"/>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
