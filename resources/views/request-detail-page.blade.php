{{-- Standalone Request Detail page (route: monitor.requests.show). Unlike
     the tab-based dashboard views, this page owns its own URL and fetches
     everything it needs itself — see Http\Controllers\RequestDetailController. --}}
@php
    $method = $root->payload['method'] ?? '';
    $path = $root->payload['path'] ?? $root->key;
@endphp
<x-monitor::layout :title="trim($method.' '.$path)">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="$tab" :range="$range" :refresh="$refresh" :app-initial="$appInitial" :auto-refreshes="false"/>

        <div class="flex min-w-0 flex-1 flex-col">
            <x-monitor::requests.header :root="$root" :range="$range" :job="$job" :breadcrumb-tab="$breadcrumbTab" :breadcrumb-label="$breadcrumbLabel" :breadcrumb-url="$breadcrumbUrl"/>

            <main class="mx-auto w-full max-w-[calc(100%-10px)] flex-1 space-y-4 px-4 pb-10 md:px-8">
                {{-- start card general info --}}
                @if ($job)
                    <x-monitor::jobs.summary :root="$job" :queued-at="$queuedAt"/>
                @else
                    <x-monitor::requests.summary :root="$root" :user-name="$userName" :timezone="$timezone"/>
                @endif
                {{-- end card general info --}}
                {{-- Headers/Body are HTTP-specific — a job has neither, same
                     as the standalone Job Attempt page (job-attempt-page.blade.php),
                     so both hide rather than keep showing the request's own
                     data while a job is the page's active entity. --}}
                @unless ($job)
                    <x-monitor::requests.headers-section
                        :request-headers="$root->payload['request_headers'] ?? []"
                        :response-headers="$root->payload['response_headers'] ?? []"
                    />
                    <x-monitor::requests.body-section :body="$root->payload['body'] ?? null"/>
                @endunless

                <x-monitor::requests.event-summary :summary="$summary"/>

                <x-monitor::requests.timeline :tracks="$tracks" :default-track="$defaultTrack" :job-base-url="$jobBaseUrl"/>

            </main>
        </div>
    </div>
</x-monitor::layout>
