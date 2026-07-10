@php
    $fmt = fn ($ms) => \LaravelMonitor\Support\Format::duration($ms);
    $tz = \LaravelMonitor\Support\Format::timezone();
@endphp
<div wire:poll.{{ $refresh }}s>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-monitor::requests-chart-card
            :count="$stats->count" :ok="$okRequests" :client="$clientErrors" :server="$serverErrors"
            :ok-buckets="$okBuckets" :client-buckets="$clientErrorBuckets" :server-buckets="$serverErrorBuckets"
            :since="$since" :until="$until"/>
        <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until" :threshold="$threshold"/>
    </div>

    {{-- Individual requests --}}
    <div class="mt-6">
        <div class="flex items-center gap-2 px-1 pb-3">
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::REQUESTS" class="h-4 w-4 text-blue-600"/>
            <h2 class="font-semibold text-neutral-900">{{ number_format($entries->count()) }} {{ $entries->count() === 1 ? 'Request' : 'Requests' }}</h2>
        </div>
        <x-monitor::card class="p-4">
            @if ($entries->isEmpty())
                <p class="py-6 text-center text-sm text-neutral-400">No individual requests recorded in this period.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 text-left font-mono text-xs uppercase tracking-tight text-neutral-500">
                            <th class="pb-2 font-normal">Date</th>
                            <th class="pb-2 font-normal">Method</th>
                            <th class="pb-2 font-normal">Details</th>
                            <th class="pb-2 text-right font-normal">Status</th>
                            <th class="pb-2 text-right font-normal">Duration</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($entries as $entry)
                            @php($status = (int) ($entry->payload['status'] ?? 0))
                            <tr class="hover:bg-neutral-50">
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-700">{{ \LaravelMonitor\Support\Format::datetime($entry->created_at) }} <span class="text-neutral-300">{{ $tz }}</span></td>
                                <td class="py-2 pr-3 font-mono text-xs text-neutral-600">{{ $entry->payload['method'] ?? '—' }}</td>
                                <td class="max-w-[18rem] truncate py-2 pr-3 font-mono text-xs text-neutral-600">{{ $entry->payload['path'] ?? '—' }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ $status >= 500 ? 'text-rose-600' : ($status >= 400 ? 'text-amber-600' : 'text-emerald-600') }}">{{ $status ?: '—' }}</td>
                                <td class="py-2 text-right font-mono text-xs {{ ($entry->duration ?? 0) >= $threshold ? 'text-amber-600' : 'text-neutral-600' }}">{{ $fmt($entry->duration) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-monitor::card>
    </div>
</div>
