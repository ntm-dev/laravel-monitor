@php
    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $startedAt = function ($entry) {
        $start = \LaravelMonitor\Support\Format::startedAt($entry);

        return $start !== null ? \LaravelMonitor\Support\Format::datetime($start) : '—';
    };
    $tz = \LaravelMonitor\Support\Format::timezone();
    $from = ($page - 1) * $perPage;
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
         x-data="{
             hoverIndex: null,
             setHoverIndex(i) { this.hoverIndex = i },
             clearHoverIndex() { this.hoverIndex = null },
         }">
        <x-monitor::card class="flex flex-col p-4">
            <x-monitor::metric :label="__('monitor::messages.common.calls')" :value="number_format($success + $failed)">
                <x-monitor::legend :label="__('monitor::messages.common.unsuccessful')" dot="bg-rose-500" :value="number_format($failed)" :color="$failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-900 dark:text-neutral-100'"/>
                <x-monitor::legend :label="__('monitor::messages.common.successful')" dot="bg-emerald-500" :value="number_format($success)"/>
            </x-monitor::metric>
            <div class="mt-5">
                <x-monitor::bar-chart :since="$since" :until="$until" height="h-[167px]" :series="[
                    ['label' => __('monitor::messages.common.successful'), 'dot' => 'bg-emerald-500', 'data' => $successBuckets],
                    ['label' => __('monitor::messages.common.unsuccessful'), 'dot' => 'bg-rose-500', 'data' => $failedBuckets],
                ]"/>
            </div>
            <x-monitor::chart-footer :since="$since" :until="$until"/>
        </x-monitor::card>
        <x-monitor::duration-chart-card :label="__('monitor::messages.common.duration')" :duration="$duration" :since="$since" :until="$until" height="h-[167px]"/>
    </div>

    {{-- Individual runs --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COMMANDS" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
            <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalEntries) }} {{ trans_choice('monitor::messages.common.run_count', $totalEntries) }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_runs_recorded_in_period') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                            <th class="pb-2 font-normal">{{ __('monitor::messages.command.started_at') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.command') }}</th>
                            <th class="pb-2 font-normal">{{ __('monitor::messages.common.status') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.exit_code') }}</th>
                            <th class="pb-2 text-right font-normal">{{ __('monitor::messages.common.duration') }}</th>
                            <th class="w-8 pb-2"></th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($entries as $entry)
                            @php($runUrl = ($entry->request_id ?? null) ? route('monitor.commands.runs.show', $entry->request_id) : null)
                            <tr class="{{ $runUrl ? 'group cursor-pointer' : '' }} hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                @if ($runUrl) onclick="window.location='{{ $runUrl }}'" @endif>
                                {{-- The run's start, not its created_at: entries are stamped when
                                     they finish, so showing created_at here put this row a second
                                     ahead of the "Started at" on the very run page it links to. --}}
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $startedAt($entry) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                {{-- Falls back to the bare command name (the key every run here
                                     shares) when payload.command is absent — a command invoked
                                     without any arguments carries nothing extra to show (see
                                     Recorders\Commands::commandLine()), same fallback as
                                     command-run-page.blade.php's $invocation. --}}
                                <td class="max-w-xs truncate py-2 pr-3 font-mono text-xs text-neutral-500 dark:text-neutral-400" data-tooltip="{{ $entry->payload['command'] ?? $entry->key }}">{{ $entry->payload['command'] ?? $entry->key }}</td>
                                <td class="py-2 pr-3">
                                    <span @class([
                                        'rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase',
                                        'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $entry->subtype === 'success',
                                        'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400' => $entry->subtype === 'failed',
                                    ])>{{ $entry->subtype }}</span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $entry->payload['exit_code'] ?? '—' }}</td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ $fmt($entry->duration) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    @if ($runUrl)
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-300 dark:text-neutral-600 group-hover:border-neutral-200 dark:group-hover:border-neutral-700 group-hover:bg-white dark:group-hover:bg-neutral-900 group-hover:text-neutral-600 dark:group-hover:text-neutral-300 group-hover:shadow-sm">
                                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3"/>
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-100 dark:divide-neutral-800">
                        <x-monitor::table-skeleton :columns="6" :rows="count($entries)"/>
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
