{{-- Standalone Request Detail page (route: monitor.requests.show, see
     Http\Controllers\RequestDetailController). $infos has one bundle per track
     ('root' plus one per job track), all rendered behind x-show; a track row's
     $navigateClick (timeline-row.blade.php) toggles them. The timeline sits outside. --}}
@php
    $activeInfo = $infos[$activeInfoId];
@endphp
<x-monitor::layout :title="$activeInfo['title']">
    <div class="flex min-h-screen"
        x-data="{
            activeInfo: {{ \Illuminate\Support\Js::from($activeInfoId) }},
            infoTitles: {{ \Illuminate\Support\Js::from(collect($infos)->map(fn (array $info) => $info['title'])->all()) }},
        }">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="$activeInfo['tab']" :range="$range" :refresh="$refresh" :app-initial="$appInitial" :auto-refreshes="false" reactive-tab-expr="(activeInfo === 'root' ? 'requests' : 'jobs')"/>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- `contents` keeps the sticky header's containing block the page column;
                 a plain wrapper div would leave it no room to stick. --}}
            @foreach ($infos as $id => $info)
                <div class="contents" x-show="activeInfo === {{ \Illuminate\Support\Js::from($id) }}">
                    <x-monitor::requests.header :root="$info['root']" :range="$range" :job="$info['isJob'] ? $info['root'] : null" :breadcrumb-tab="$info['tab']" :breadcrumb-label="$info['breadcrumbLabel']" :breadcrumb-url="$info['breadcrumbUrl']"/>
                </div>
            @endforeach

            <main class="mx-auto w-full max-w-[calc(100%-10px)] flex-1 space-y-4 px-4 pb-10 md:px-8">
                @foreach ($infos as $id => $info)
                    <div x-show="activeInfo === {{ \Illuminate\Support\Js::from($id) }}" class="space-y-4">
                        {{-- start card general info --}}
                        @if ($info['isJob'])
                            <x-monitor::jobs.summary :root="$info['root']" :queued-at="$info['queuedAt']" :queued-from="$info['queuedFrom']"/>
                        @else
                            <x-monitor::requests.summary :root="$info['root']" :user-name="$info['userName']" :timezone="$timezone"/>
                        @endif
                        {{-- end card general info --}}
                        {{-- HTTP-only sections: hidden while a job track is active. --}}
                        @unless ($info['isJob'])
                            <x-monitor::requests.message-section
                                :title="__('monitor::messages.common.request')"
                                :headers="$info['root']->payload['request']['headers'] ?? []"
                                :body="$info['root']->payload['request']['body'] ?? null"
                                :size="$info['root']->payload['request']['size'] ?? null"
                            />
                            <x-monitor::requests.message-section
                                :title="__('monitor::messages.common.response')"
                                :headers="$info['root']->payload['response']['headers'] ?? []"
                                :body="$info['root']->payload['response']['body'] ?? null"
                                :size="$info['root']->payload['response']['size'] ?? null"
                            />
                            {{-- Fetched after the page loads, hidden when empty. --}}
                            <x-monitor::requests.deferred-card :url="route('monitor.requests.exceptions', $info['root']->request_id)"/>
                            <x-monitor::requests.deferred-card :url="route('monitor.requests.logs', $info['root']->request_id)"/>
                        @endunless

                        <x-monitor::requests.event-summary :summary="$info['summary']"/>
                    </div>
                @endforeach

                <x-monitor::requests.timeline :tracks="$tracks" :default-track="$defaultTrack" :job-base-url="$jobBaseUrl" :scroll-to-outcome-id="$scrollToOutcomeId"/>

            </main>
        </div>
    </div>
</x-monitor::layout>
