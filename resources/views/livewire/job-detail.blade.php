@php
    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $tz = \LaravelMonitor\Support\Format::timezone();
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-monitor::jobs-chart-card
            :queued="$queued" :processed="$processed" :failed="$failed"
            :queued-buckets="$queuedBuckets" :processed-buckets="$processedBuckets" :failed-buckets="$failedBuckets"
            :since="$since" :until="$until"/>
        <x-monitor::duration-chart-card label="Job duration" :duration="$duration" :since="$since" :until="$until"/>
    </div>

    {{-- Individual job runs --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::JOBS" class="h-4 w-4 text-blue-600"/>
            <h2 class="font-semibold text-neutral-900">{{ number_format($entries->count()) }} {{ $entries->count() === 1 ? 'Job Run' : 'Job Runs' }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400">No job runs recorded in this period.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 text-left font-mono text-xs uppercase tracking-tight text-neutral-500">
                            <th class="pb-2 font-normal">Date</th>
                            <th class="pb-2 font-normal">Queue</th>
                            <th class="pb-2 font-normal">Status</th>
                            <th class="pb-2 text-right font-normal">Duration</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($entries as $entry)
                            <tr class="hover:bg-neutral-50">
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-700">{{ \LaravelMonitor\Support\Format::datetime($entry->created_at) }} <span class="text-neutral-300">{{ $tz }}</span></td>
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-600">{{ $entry->payload['queue'] ?? 'default' }}</td>
                                <td class="py-2 pr-3">
                                    <span @class([
                                        'rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase',
                                        'border-emerald-200 bg-emerald-50 text-emerald-600' => $entry->subtype === 'processed',
                                        'border-amber-200 bg-amber-50 text-amber-600' => $entry->subtype === 'queued',
                                        'border-rose-200 bg-rose-50 text-rose-600' => $entry->subtype === 'failed',
                                    ])>{{ $entry->subtype }}</span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-neutral-600">{{ $fmt($entry->duration) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-monitor::card>
    </div>
</div>
