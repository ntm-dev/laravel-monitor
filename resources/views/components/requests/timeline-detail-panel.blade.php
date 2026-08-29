{{-- Selected event detail panel: a right-hand side panel next to the
     chart, not a bottom drawer, so inspecting an event never pushes the
     timeline down the page. The inner wrapper is `sticky` (not the outer
     flex item) so it pins near the top of the viewport for as long as the
     row list beside it is tall enough to scroll through. Its offset is
     `timelineHeaderOffset` (see timeline-script.blade.php), sitting it
     flush against the sticky title/zoom/ruler header above — using the
     same offset as that header would make both sticky elements land on the
     same viewport band, and whichever paints later would cover the other.
     No x-transition: it silently broke the very first selection after page
     load (stayed at width 0 until toggled twice) — verified by removing
     just that, everything else equal. --}}
<div x-show="selectedId !== null" class="w-80 shrink-0">
    <div :style="'top: ' + timelineHeaderOffset + 'px; max-height: calc(100vh - ' + timelineHeaderOffset + 'px)'"
        class="sticky divide-y divide-neutral-200 overflow-y-auto dark:divide-neutral-800">
        <div class="flex items-start justify-between gap-2 p-4">
            <div class="min-w-0">
                <h3 class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400"
                    x-text="selected()?.badge"></h3>
                <span class="mt-0.5 block font-mono text-xs text-neutral-400 dark:text-neutral-500"
                    x-text="selectedTimestamp()"></span>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <template x-if="selected()?.type === 'query'">
                    <a :href="selected()?.queryUrl" title="{{ __('monitor::messages.common.view_query') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <template x-if="selected()?.type === 'notification'">
                    <a :href="selected()?.notificationUrl" title="{{ __('monitor::messages.common.view_notification') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <template x-if="selected()?.type === 'mail'">
                    <a :href="selected()?.mailUrl" title="{{ __('monitor::messages.common.view_mail') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <template x-if="selected()?.type === 'exception'">
                    <a :href="selected()?.exceptionUrl" title="{{ __('monitor::messages.common.view_exception') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <template x-if="selected()?.type === 'http'">
                    <a :href="selected()?.outgoingUrl" title="{{ __('monitor::messages.common.view_outgoing_request') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <button type="button" @click="closeDetail()"
                    class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOSE" :stroke="2" class="h-4 w-4" />
                </button>
            </div>
        </div>

        {{-- SQL — highlighted via the highlight.js build already loaded for
             stack traces (see components/layout.blade.php). --}}
        <template x-if="selected()?.type === 'query'">
            <div class="p-4">
                <div class="mb-1.5 flex items-center justify-between">
                    <span
                        class="font-mono text-[10px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.sql') }}</span>
                    <button type="button" @click="copySql()" title="{{ __('monitor::messages.common.copy') }}"
                        class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" class="h-3.5 w-3.5" x-show="! sqlCopied" />
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2"
                            class="h-3.5 w-3.5 text-emerald-500" x-show="sqlCopied" x-cloak
                            x-transition:enter="transition-[clip-path] ease-out duration-1000"
                            x-transition:enter-start="[clip-path:inset(0_100%_0_0)]"
                            x-transition:enter-end="[clip-path:inset(0_0_0_0)]" />
                    </button>
                </div>
                <div class="max-h-64 overflow-auto">
                    <pre class="whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-neutral-800 dark:text-neutral-200"><code data-line-code data-lang="sql" x-html="sqlHighlighted()"></code></pre>
                </div>
            </div>
        </template>

        <template x-if="selected()?.type === 'query'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.duration + 'ms'"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duplicates') }}</dt>
                    <dd class="font-mono"
                        :class="selected()?.duplicateCount > 1 ? 'font-medium text-amber-600 dark:text-amber-400' :
                            'text-neutral-800 dark:text-neutral-200'"
                        x-text="selected()?.duplicateCount"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.connection') }}</dt>
                    <dd class="flex items-center gap-1.5 font-mono text-neutral-800 dark:text-neutral-200">
                        <span x-text="selected()?.metadata?.connection"></span>
                        {{-- The PDO connection role Laravel actually routed
                             to (read/write/direct — Recorders\Queries), not
                             guessed from the SQL verb. Omitted when the
                             running Laravel doesn't report it (< 12.45) or
                             it's ambiguous. --}}
                        <template x-if="selected()?.metadata?.connection_type">
                            <span class="rounded px-1 py-0.5 text-[10px] font-medium uppercase"
                                :class="({
                                    write: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                                    read: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
                                    direct: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
                                })[selected()?.metadata?.connection_type]"
                                x-text="selected()?.metadata?.connection_type"></span>
                        </template>
                    </dd>
                </div>
                <template x-if="selected()?.metadata?.location">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.file') }}</dt>
                        {{-- rtl + text-align:left truncates the front of the
                             path, keeping file:line visible. --}}
                        <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                            style="direction: rtl; text-align: left;" :title="selected()?.metadata?.location"
                            x-text="selected()?.metadata?.location"></dd>
                    </div>
                </template>
            </dl>
        </template>

        <template x-if="selected()?.type === 'cache'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.key') }}</dt>
                    <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                        :title="selected()?.metadata?.key" x-text="selected()?.metadata?.key"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.operation') }}</dt>
                    <dd class="font-mono uppercase text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.metadata?.subtype"></dd>
                </div>
                <template x-if="selected()?.metadata?.store">
                    <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.store') }}</dt>
                        <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="selected()?.metadata?.store"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.ttl">
                    <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.ttl') }}</dt>
                        <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="selected()?.metadata?.ttl + 's'"></dd>
                    </div>
                </template>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.duration + 'ms'"></dd>
                </div>
            </dl>
        </template>

        <template x-if="selected()?.type === 'notification'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.notification') }}</dt>
                    {{-- rtl + text-align:left truncates the front of the
                         FQCN, keeping the class name visible. --}}
                    <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                        style="direction: rtl; text-align: left;" :title="selected()?.metadata?.notification"
                        x-text="selected()?.label"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.channel') }}</dt>
                    <dd class="font-mono uppercase text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.metadata?.channel"></dd>
                </div>
                <template x-if="selected()?.metadata?.notifiable">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.notifiable') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :title="selected()?.metadata?.notifiable"
                            x-text="selected()?.metadata?.notifiable"></dd>
                    </div>
                </template>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.duration + 'ms'"></dd>
                </div>
            </dl>
        </template>

        <template x-if="selected()?.type === 'mail'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.subject') }}</dt>
                    <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                        :title="selected()?.metadata?.subject" x-text="selected()?.metadata?.subject"></dd>
                </div>
                <template x-if="mailRecipients()">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.recipients') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="mailRecipients()"></dd>
                    </div>
                </template>
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.to') }}</dt>
                    <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                        :title="selected()?.metadata?.to" x-text="selected()?.metadata?.to"></dd>
                </div>
                <template x-if="selected()?.metadata?.cc">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.cc') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :title="selected()?.metadata?.cc" x-text="selected()?.metadata?.cc"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.bcc">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.bcc') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :title="selected()?.metadata?.bcc" x-text="selected()?.metadata?.bcc"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.notification">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.via') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :title="selected()?.metadata?.notification"
                            x-text="selected()?.metadata?.notification"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.attachments">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.attachments') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :title="mailAttachments()" x-text="mailAttachments()"></dd>
                    </div>
                </template>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.duration + 'ms'"></dd>
                </div>
                <template x-if="mailClass()">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.class') }}</dt>
                        {{-- rtl + text-align:left truncates the front of the
                             FQCN, keeping the class name visible. --}}
                        <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                            style="direction: rtl; text-align: left;" :title="mailClass()"
                            x-text="mailClass()"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.mailer">
                    <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.mailer') }}</dt>
                        <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="selected()?.metadata?.mailer"></dd>
                    </div>
                </template>
            </dl>
        </template>

        <template x-if="selected()?.type === 'lazy_loading'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.model') }}</dt>
                    {{-- rtl + text-align:left truncates the front of the
                         FQCN, keeping the class name visible. --}}
                    <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                        style="direction: rtl; text-align: left;" :title="selected()?.metadata?.model"
                        x-text="selected()?.metadata?.model"></dd>
                </div>
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.relation') }}</dt>
                    <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.metadata?.relation"></dd>
                </div>
                <template x-if="selected()?.metadata?.id">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.record_id') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="selected()?.metadata?.id"></dd>
                    </div>
                </template>
            </dl>
        </template>

        <template x-if="selected()?.type === 'http'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.method') }}</dt>
                    <dd class="font-mono uppercase text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.metadata?.method"></dd>
                </div>
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.status') }}</dt>
                    <dd class="font-mono font-medium"
                        :class="selected()?.metadata?.status == null ? 'text-neutral-400 dark:text-neutral-500' :
                            selected()?.metadata?.status >= 500 ? 'text-rose-600 dark:text-rose-400' :
                            selected()?.metadata?.status >= 400 ? 'text-amber-600 dark:text-amber-400' :
                            'text-emerald-600 dark:text-emerald-400'"
                        x-text="selected()?.metadata?.status ?? 'Failed'"></dd>
                </div>
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.url') }}</dt>
                    <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                        :title="selected()?.metadata?.url" x-text="selected()?.metadata?.url"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.duration !== null ? selected()?.duration + 'ms' : '—'"></dd>
                </div>
            </dl>
        </template>

        <template x-if="selected()?.type === 'queue'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.job') }}</dt>
                    {{-- rtl + text-align:left truncates the front of the
                         FQCN, keeping the class name visible. --}}
                    <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                        style="direction: rtl; text-align: left;" :title="selected()?.metadata?.key"
                        x-text="selected()?.metadata?.key"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.status') }}</dt>
                    <dd class="font-mono font-medium uppercase"
                        :class="({ processed: 'text-emerald-600 dark:text-emerald-400',
                            failed: 'text-rose-600 dark:text-rose-400',
                            released: 'text-amber-600 dark:text-amber-400',
                            queued: 'text-neutral-500 dark:text-neutral-400' })[selected()?.metadata
                        ?.subtype] ?? 'text-neutral-800 dark:text-neutral-200'"
                        x-text="selected()?.metadata?.subtype ?? 'queued'"></dd>
                </div>
                <template x-if="selected()?.metadata?.queue">
                    <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.queue') }}</dt>
                        <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="selected()?.metadata?.queue"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.connection">
                    <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.connection') }}</dt>
                        <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="selected()?.metadata?.connection"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.attempts">
                    <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.attempt') }}</dt>
                        <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="'#' + selected()?.metadata?.attempts"></dd>
                    </div>
                </template>
                {{-- Only set once a job outcome (processed/failed/released)
                     was actually recorded (Recorders\Jobs) — absent for a
                     still-'queued' placeholder. --}}
                <template x-if="selected()?.metadata?.peak_memory">
                    <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.peak_memory') }}</dt>
                        <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="formatBytes(selected()?.metadata?.peak_memory)"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.server">
                    <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.server') }}</dt>
                        <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                            x-text="selected()?.metadata?.server"></dd>
                    </div>
                </template>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        x-text="selected()?.duration !== null ? selected()?.duration + 'ms' : '—'"></dd>
                </div>
            </dl>
        </template>

        <template x-if="selected()?.type === 'exception'">
            <div class="p-4">
                <span
                    class="font-mono text-[10px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.message') }}</span>
                <p class="mt-1.5 max-h-40 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-neutral-800 dark:text-neutral-200"
                    x-text="selected()?.metadata?.message"></p>
            </div>
        </template>
        <template x-if="selected()?.type === 'exception'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.class') }}</dt>
                    {{-- rtl + text-align:left truncates the front of the
                         FQCN, keeping the class name visible. --}}
                    <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                        style="direction: rtl; text-align: left;" :title="selected()?.metadata?.class"
                        x-text="selected()?.metadata?.class"></dd>
                </div>
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.file') }}</dt>
                    <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                        style="direction: rtl; text-align: left;" :title="exceptionLocation()"
                        x-text="exceptionLocation()"></dd>
                </div>
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.handled') }}</dt>
                    <dd class="font-mono font-medium"
                        :class="selected()?.metadata?.handled ? 'text-emerald-600 dark:text-emerald-400' :
                            'text-rose-600 dark:text-rose-400'"
                        x-text="selected()?.metadata?.handled ? 'True' : 'False'"></dd>
                </div>
            </dl>
        </template>
    </div>
</div>
