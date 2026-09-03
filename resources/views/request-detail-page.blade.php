{{-- Standalone Request Detail page (route: monitor.requests.show). Unlike
     the tab-based dashboard views, this page owns its own URL and fetches
     everything it needs itself — see Http\Controllers\RequestDetailController.

     $infos holds one bundle per track this page can show at the top (the
     request's own, id 'root', plus one per resolved job track) — every
     bundle's own breadcrumb/header/general-info/headers/body/event-summary
     renders up front, each behind its own x-show, so switching between them
     (clicking a track's own row in the timeline below, see
     timeline-row.blade.php's $navigateClick) is a plain Alpine toggle
     instead of a real navigation — the browser already has every byte of
     whichever one it switches to. The timeline itself sits outside this
     toggle entirely: its own fixed scale/expand state never depends on
     which info bundle is active. --}}
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
            @foreach ($infos as $id => $info)
                <div x-show="activeInfo === {{ \Illuminate\Support\Js::from($id) }}">
                    <x-monitor::requests.header :root="$info['root']" :range="$range" :job="$info['isJob'] ? $info['root'] : null" :breadcrumb-tab="$info['tab']" :breadcrumb-label="$info['breadcrumbLabel']" :breadcrumb-url="$info['breadcrumbUrl']"/>
                </div>
            @endforeach

            <main class="mx-auto w-full max-w-[calc(100%-10px)] flex-1 space-y-4 px-4 pb-10 md:px-8">
                @foreach ($infos as $id => $info)
                    <div x-show="activeInfo === {{ \Illuminate\Support\Js::from($id) }}" class="space-y-4">
                        {{-- start card general info --}}
                        @if ($info['isJob'])
                            <x-monitor::jobs.summary :root="$info['root']" :queued-at="$info['queuedAt']"/>
                        @else
                            <x-monitor::requests.summary :root="$info['root']" :user-name="$info['userName']" :timezone="$timezone"/>
                        @endif
                        {{-- end card general info --}}
                        {{-- Headers/Body are HTTP-specific — a job has
                             neither, same as the standalone Job Attempt page
                             (job-attempt-page.blade.php), so both hide
                             rather than keep showing the request's own data
                             while a job is the page's active entity. --}}
                        @unless ($info['isJob'])
                            <x-monitor::requests.headers-section
                                :request-headers="$info['root']->payload['request_headers'] ?? []"
                                :response-headers="$info['root']->payload['response_headers'] ?? []"
                            />
                            <x-monitor::requests.body-section :body="$info['root']->payload['body'] ?? null"/>
                        @endunless

                        <x-monitor::requests.event-summary :summary="$info['summary']"/>
                    </div>
                @endforeach

                <x-monitor::requests.timeline :tracks="$tracks" :default-track="$defaultTrack" :job-base-url="$jobBaseUrl" :scroll-to-outcome-id="$scrollToOutcomeId"/>

            </main>
        </div>
    </div>
</x-monitor::layout>
