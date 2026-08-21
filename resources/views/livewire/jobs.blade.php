@php
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\KeyHash;

    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);

    $columns = [
        'key' => ['label' => __('monitor::messages.common.job'), 'align' => 'left'],
        'queued' => ['label' => __('monitor::messages.common.queued'), 'align' => 'right'],
        'processed' => ['label' => __('monitor::messages.common.processed'), 'align' => 'right'],
        'released' => ['label' => __('monitor::messages.common.released'), 'align' => 'right'],
        'failed' => ['label' => __('monitor::messages.common.failed'), 'align' => 'right'],
        'avg_duration' => ['label' => __('monitor::messages.common.avg'), 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section>
        <x-slot:actions>
            <button type="button" wire:click="$refresh" title="{{ __('monitor::messages.common.refresh') }}"
                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-neutral-200 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-400 shadow-neu-sm dark:shadow-neu-dark-sm hover:shadow-neu dark:hover:shadow-neu-dark active:shadow-neu-inset dark:active:shadow-neu-dark-inset">
                <x-monitor::icon :path="Icons::REFRESH" :stroke="1.8" class="h-3.5 w-3.5"/>
            </button>
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
                       class="h-8 w-56 rounded-xl bg-neutral-200 dark:bg-neutral-800 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-neu-inset dark:shadow-neu-dark-inset">
            </div>
        </div>

        @if ($jobs->isEmpty())
            <x-monitor::empty-state :label="__('monitor::messages.nav.jobs')" :message="__('monitor::messages.common.no_jobs_recorded')" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="shadow-[0_1px_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_rgba(255,255,255,0.06)] text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            @foreach ($columns as $field => $column)
                                <th class="cursor-pointer select-none pb-2 font-normal {{ $column['align'] === 'right' ? 'text-right' : 'text-left' }}"
                                    wire:click="sort('{{ $field }}')">
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
                    <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                        @foreach ($jobs as $job)
                            <tr class="group cursor-pointer rounded-lg hover:shadow-neu-inset dark:hover:shadow-neu-dark-inset"
                                onclick="window.location='{{ route('monitor.jobs.show', ['hash' => KeyHash::for($job->key)] + $range) }}'">
                                <td class="max-w-[24rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $job->key }}">{{ $job->key }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($job->queued) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-emerald-600 dark:text-emerald-400">{{ number_format($job->processed) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $job->released > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($job->released) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $job->failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($job->failed) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($job->avg_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($job->avg_duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg text-neutral-300 dark:text-neutral-600 group-hover:bg-neutral-200 dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-neu-sm dark:group-hover:shadow-neu-dark-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-300/60 dark:divide-neutral-600/60">
                        <x-monitor::table-skeleton :columns="7" :rows="count($jobs)"/>
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
