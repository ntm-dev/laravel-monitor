<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::ACTIVITY" title="Activity">
        <x-slot:actions>
            <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'requests'] + $range)" external>Requests</x-monitor::link-button>
        </x-slot:actions>

        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
             x-data="{
                 hoverIndex: null,
                 setHoverIndex(i) { this.hoverIndex = i },
                 clearHoverIndex() { this.hoverIndex = null },
             }">
            <x-monitor::requests-chart-card
                :count="$requests->count" :ok="$okRequests" :client="$clientErrors" :server="$serverErrors"
                :ok-buckets="$okBuckets" :client-buckets="$clientErrorBuckets" :server-buckets="$serverErrorBuckets"
                :since="$since" :until="$until"/>
            <x-monitor::duration-chart-card :duration="$duration" :since="$since" :until="$until"/>
        </div>
    </x-monitor::section>
</div>
