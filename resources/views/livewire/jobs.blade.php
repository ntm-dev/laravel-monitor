@php
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\KeyHash;

    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);

    $columns = [
        'key' => ['label' => 'Job', 'align' => 'left'],
        'queued' => ['label' => 'Queued', 'align' => 'right'],
        'processed' => ['label' => 'Processed', 'align' => 'right'],
        'released' => ['label' => 'Released', 'align' => 'right'],
        'failed' => ['label' => 'Failed', 'align' => 'right'],
        'avg_duration' => ['label' => 'Avg', 'align' => 'right'],
    ];

    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="Icons::JOBS" title="Jobs">
        <x-slot:actions>
            <button type="button" wire:click="$refresh" title="Refresh"
                    class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
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
            <x-monitor::jobs-chart-card label="Attempts"
                :processed="$processed" :failed="$failed" :released="$released"
                :processed-buckets="$processedBuckets" :failed-buckets="$failedBuckets" :released-buckets="$releasedBuckets"
                :since="$since" :until="$until" height="h-[167px]"/>
            <x-monitor::duration-chart-card label="Job duration" :duration="$duration" :since="$since" :until="$until" :threshold="$threshold" height="h-[167px]"/>
        </div>

        {{-- Job table --}}
        <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalJobs) }} {{ $totalJobs === 1 ? 'Job' : 'Jobs' }}</h3>
            <div class="relative">
                <x-monitor::icon :path="Icons::SEARCH" :stroke="1.8" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500"/>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search jobs…"
                       class="h-8 w-56 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 pl-8 pr-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
            </div>
        </div>

        @if ($jobs->isEmpty())
            <x-monitor::empty-state label="Jobs" message="No jobs recorded" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card class="p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            @foreach ($columns as $field => $column)
                                <th class="cursor-pointer select-none pb-2 font-normal {{ $column['align'] === 'right' ? 'text-right' : 'text-left' }}"
                                    wire:click="sort('{{ $field }}')">
                                    <span class="inline-flex items-center gap-1 {{ $column['align'] === 'right' ? 'flex-row-reverse' : '' }}">
                                        {{ $column['label'] }}
                                        @if ($sortBy === $field)
                                            <x-monitor::icon :path="Icons::CHEVRON_DOWN" :stroke="2" class="h-3 w-3 {{ $sortDirection === 'asc' ? 'rotate-180' : '' }}"/>
                                        @endif
                                    </span>
                                </th>
                            @endforeach
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($jobs as $job)
                            <tr class="group cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                onclick="window.location='{{ route('monitor.jobs.show', ['hash' => KeyHash::for($job->key)] + $range) }}'">
                                <td class="max-w-[14rem] truncate py-2 pr-2 font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $job->key }}">{{ class_basename($job->key) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($job->queued) }}</td>
                                <td class="py-2 text-right font-mono text-xs text-emerald-600 dark:text-emerald-400">{{ number_format($job->processed) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $job->released > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($job->released) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $job->failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-300 dark:text-neutral-600' }}">{{ number_format($job->failed) }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($job->avg_duration ?? 0) >= $threshold ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ $fmt($job->avg_duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-sm">
                                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($lastPage > 1)
                    <div class="mt-3 flex items-center justify-between border-t border-neutral-100 dark:border-neutral-800 pt-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                        <span>Showing {{ $from + 1 }}–{{ min($from + $perPage, $totalJobs) }} of {{ number_format($totalJobs) }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" wire:click="previousPage" @disabled($page <= 1)
                                    class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">Prev</button>
                            <span>{{ $page }} / {{ $lastPage }}</span>
                            <button type="button" wire:click="nextPage" @disabled($page >= $lastPage)
                                    class="rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2.5 py-1 disabled:opacity-40">Next</button>
                        </div>
                    </div>
                @endif
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
