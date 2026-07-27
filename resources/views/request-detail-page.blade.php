{{-- Standalone Request Detail page (route: monitor.requests.show). Unlike
     the tab-based dashboard views, this page owns its own URL and fetches
     everything it needs itself — see Http\Controllers\RequestDetailController. --}}
@php
    $method = $root->payload['method'] ?? '';
    $path = $root->payload['path'] ?? $root->key;
@endphp
<x-monitor::layout :title="trim($method.' '.$path)">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="$tab" :range="$range" :refresh="$refresh" :app-initial="$appInitial"/>

        <div class="flex min-w-0 flex-1 flex-col">
            <x-monitor::requests.header :root="$root" :range="$range"/>

            <main class="mx-auto w-full max-w-[1600px] flex-1 space-y-4 px-4 pb-10 md:px-8">
                <x-monitor::requests.summary :root="$root" :user-name="$userName" :timezone="$timezone"/>
                <x-monitor::requests.headers-section
                    :request-headers="$root->payload['request_headers'] ?? []"
                    :response-headers="$root->payload['response_headers'] ?? []"
                />
                <x-monitor::requests.body-section :body="$root->payload['body'] ?? null"/>

                <x-monitor::requests.event-summary :summary="$summary"/>

                <x-monitor::requests.timeline :entries="$timeline" :total-duration="$totalDuration"/>

            </main>
        </div>
    </div>
</x-monitor::layout>
