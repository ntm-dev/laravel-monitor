{{-- Standalone event list page (routes: monitor.requests.routes.request.events,
     monitor.jobs.attempts.events, monitor.commands.runs.events): every
     occurrence of one event type (queries, cache, mail, ...) recorded
     against a single request/job/command — see Http\Controllers\EventListController.
     Linked from the matching EventSummary card on that root's detail page. --}}
@php
    use LaravelMonitor\Support\Format;

    $tz = Format::timezone();
@endphp
<x-monitor::layout :title="$typeLabel">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="$tab" :range="$range" :refresh="$refresh" :app-initial="$appInitial"/>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- start page header --}}
            <header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
                <div class="mx-auto w-full max-w-[1600px] px-4 py-5 md:px-8">
                    <a href="{{ $backUrl }}" class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">
                        ← Back
                    </a>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight">{{ number_format($rows->count()) }} {{ $typeLabel }}</h1>
                </div>
            </header>
            {{-- end page header --}}

            <main class="mx-auto w-full max-w-[1600px] flex-1 space-y-4 px-4 pb-10 md:px-8">
                {{-- start event list card --}}
                <x-monitor::card class="p-4">
                    @if ($rows->isEmpty())
                        <p class="py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">No {{ strtolower($typeLabel) }} recorded.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                    <th class="pb-2 font-normal">Date</th>
                                    <th class="pb-2 font-normal">Detail</th>
                                    <th class="pb-2 text-right font-normal">Duration</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                @foreach ($rows as $row)
                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                        <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap text-neutral-500 dark:text-neutral-400">
                                            {{ Format::datetime($row['createdAt']) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span>
                                        </td>
                                        <td class="max-w-xl truncate py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200" title="{{ $row['detail'] }}">
                                            @if ($row['url'])
                                                <a href="{{ $row['url'] }}" class="text-blue-600 hover:underline dark:text-blue-400">{{ $row['detail'] }}</a>
                                            @else
                                                {{ $row['detail'] }}
                                            @endif
                                        </td>
                                        <td class="py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ Format::duration($row['entry']->duration) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-monitor::card>
                {{-- end event list card --}}
            </main>
        </div>
    </div>
</x-monitor::layout>
