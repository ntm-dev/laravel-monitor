{{-- Dashboard page: composes the layout, sidebar and header components and
     mounts the Livewire card for the active tab. All data is prepared by
     Http\Controllers\DashboardController. --}}
<x-monitor::layout :title="$pageTitle">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="$tab" :range="$range" :refresh="$refresh" :app-initial="$appInitial"/>

        <div class="flex min-w-0 flex-1 flex-col">
            <x-monitor::header :tab="$tab" :tabs="$tabs" :groups="$groups" :title="$title" :detail="$detail" :key="$key" :range="$range"
                               :period="$period" :periods="$periods" :has-custom-range="$hasCustomRange" :from="$from" :to="$to"
                               :timezone="$timezone" :range-max="$rangeMax"/>

            <main class="mx-auto w-full max-w-[1600px] flex-1 px-4 pb-10 md:px-8">
                @if ($tab === 'overview')
                    <div class="space-y-4">
                        @livewire('monitor.overview', $rangeProps)
                        @livewire('monitor.application', $rangeProps)
                        @livewire('monitor.users', $rangeProps)
                    </div>
                @elseif ($tab === 'settings')
                    @include('monitor::settings')
                @elseif ($tab === 'requests' && filled($key))
                    @livewire('monitor.request-detail', $rangeProps + ['key' => $key])
                @elseif ($tab === 'jobs' && filled($key))
                    @livewire('monitor.job-detail', $rangeProps + ['key' => $key])
                @elseif ($tab === 'exceptions' && filled($key))
                    @livewire('monitor.exception-detail', $rangeProps + ['key' => $key])
                @elseif ($tab === 'queries' && filled($key))
                    @livewire('monitor.query-detail', $rangeProps + ['key' => $key])
                @else
                    @livewire($tabs[$tab]['component'], $rangeProps + ['limit' => 25])
                @endif
            </main>
        </div>
    </div>
</x-monitor::layout>
