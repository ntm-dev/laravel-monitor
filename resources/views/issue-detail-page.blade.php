{{-- Standalone Issue Detail page (route: monitor.issues.show). Shows the
     full picture for one exception, or a compact summary for a
     performance-threshold issue, plus a Manage panel (Status/Priority).
     See Http\Controllers\IssueController. --}}
<x-monitor::layout :title="'Issue #'.$issue->id">
    <div class="flex min-h-screen">
        <x-monitor::navigation :groups="$groups" :footer-tabs="$footerTabs" :tab="'issues'" :range="[]" :refresh="$refresh" :app-initial="$appInitial" :open-issue-count="$openIssueCount"/>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
                <div class="mx-auto flex w-full max-w-[1600px] items-center gap-3 px-4 py-5 md:px-8">
                    <a href="{{ route('monitor.dashboard', ['tab' => 'issues']) }}" class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">← Issues</a>
                    <span class="font-mono text-xs text-neutral-400 dark:text-neutral-500">#{{ $issue->id }}</span>
                </div>
            </header>

            <main class="mx-auto grid w-full max-w-[1600px] flex-1 grid-cols-1 gap-4 px-4 pb-10 md:px-8 lg:grid-cols-[1fr_260px]">
                <div class="min-w-0 space-y-4">
                    @if ($type === 'exception')
                        @if (! $exists)
                            <x-monitor::card class="p-10 text-center">
                                <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Exception not found</p>
                                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">This exception has no recorded occurrences.</p>
                            </x-monitor::card>
                        @else
                            <x-monitor::card class="p-4">
                                <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Summary</p>
                                <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3">
                                    @foreach ($summary as [$label, $value])
                                        <div class="flex max-w-full items-baseline gap-2 h-6 text-sm font-mono">
                                            <div class="uppercase text-neutral-500 dark:text-neutral-400 shrink-0">{{ $label }}</div>
                                            <div class="min-w-6 grow h-3 border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                                            <div class="truncate text-neutral-900 dark:text-white">{{ $value }}</div>
                                        </div>
                                    @endforeach
                                </dl>
                            </x-monitor::card>

                            <div class="flex flex-col rounded-lg border border-neutral-200 bg-white shadow-md shadow-black/5 dark:border-white/5 dark:bg-white/2 dark:shadow-black/20 overflow-hidden">
                                <div class="flex flex-col gap-3 p-4 md:p-5"
                                     x-data="{ copied: false, copy() {
                                         navigator.clipboard.writeText(@js($markdown)).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1600); });
                                     } }">
                                    <div class="flex justify-between gap-2 max-md:flex-col md:items-center">
                                        <x-monitor::status-badge :handled="$handled"/>
                                        <button type="button" @click="copy()"
                                                class="group flex h-6 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white/50 dark:bg-neutral-900/50 px-1.5 text-xs leading-none text-neutral-600 dark:text-neutral-300 hover:border-blue-500 hover:bg-white dark:hover:bg-neutral-900 hover:text-neutral-900 dark:hover:text-neutral-100 active:translate-y-px active:bg-neutral-100 dark:active:bg-neutral-800">
                                            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" :stroke="1.8" class="h-3.5 w-3.5 text-neutral-400 dark:text-neutral-500 group-hover:text-blue-600 dark:group-hover:text-blue-400"/>
                                            <span x-text="copied ? 'Copied!' : 'Copy as Markdown'"></span>
                                        </button>
                                    </div>
                                    <div class="mt-1 min-w-0 flex-1 break-all text-2xl/none font-semibold {{ $handled ? 'text-neutral-900 dark:text-neutral-100' : 'text-rose-600 dark:text-rose-400' }}" title="{{ $class }}">{{ $class }}</div>
                                    @if (filled($message))
                                        <p class="break-words text-sm text-neutral-600 dark:text-neutral-300">{{ $message }}</p>
                                    @endif
                                </div>
                                @if (! empty($frameGroups))
                                    <x-monitor::stack-trace :groups="$frameGroups"/>
                                @else
                                    <x-monitor::card class="p-8 text-center text-sm text-neutral-400 dark:text-neutral-500">No stack trace was captured for this exception.</x-monitor::card>
                                @endif
                            </div>

                            <div>
                                <div class="flex items-center gap-2 px-1 pb-3">
                                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOCK" class="h-4 w-4 text-blue-600 dark:text-blue-400"/>
                                    <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($occurrences->count()) }} {{ $occurrences->count() === 1 ? 'Occurrence' : 'Occurrences' }}</h3>
                                </div>
                                <x-monitor::card class="p-4">
                                    <div class="overflow-x-auto">
                                        <table class="w-full min-w-[640px] text-sm">
                                            <thead>
                                                <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                                    <th class="pb-2 font-normal">Date</th>
                                                    <th class="pb-2 font-normal">Source</th>
                                                    <th class="pb-2 font-normal">Message</th>
                                                    <th class="pb-2 font-normal">User</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                                @foreach ($occurrences as $occurrence)
                                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                                        <td class="whitespace-nowrap py-2 pr-3 font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $occurrence->date }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></td>
                                                        <td class="py-2 pr-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $occurrence->server ?? '—' }}</td>
                                                        <td class="max-w-[22rem] truncate py-2 pr-3 text-xs text-neutral-600 dark:text-neutral-300" title="{{ $occurrence->message }}">{{ $occurrence->message ?? '—' }}</td>
                                                        <td class="py-2 pr-3 text-xs text-neutral-600 dark:text-neutral-300">{{ $occurrence->user ?? '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </x-monitor::card>
                            </div>
                        @endif
                    @else
                        <x-monitor::card class="p-6">
                            <span class="rounded border border-neutral-200 dark:border-neutral-700 bg-neutral-100/80 dark:bg-neutral-800/80 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ $badge }}</span>
                            <p class="mt-3 break-all font-mono text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $label }}</p>
                            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Occurrences</dt>
                                    <dd class="mt-1 font-mono text-neutral-900 dark:text-neutral-100">{{ number_format($count) }}</dd>
                                </div>
                                <div>
                                    <dt class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Max duration</dt>
                                    <dd class="mt-1 font-mono text-amber-600 dark:text-amber-400">{{ \LaravelMonitor\Support\Format::duration($maxDuration) }}</dd>
                                </div>
                            </dl>
                            <a href="{{ $targetUrl }}" class="mt-4 inline-block text-sm text-blue-600 dark:text-blue-400 hover:underline">View details →</a>
                        </x-monitor::card>
                    @endif
                </div>

                <aside class="lg:sticky lg:top-20 lg:self-start">
                    <x-monitor::issues.manage-panel :issue="$issue" :statuses="$statuses" :priorities="$priorities"/>
                </aside>
            </main>
        </div>
    </div>
</x-monitor::layout>
