{{-- Selected-entry side panel. The inner wrapper is sticky at timelineHeaderOffset, just below the
     sticky timeline header. No x-transition: it left the first selection stuck at width 0. --}}
@props(['hasController' => false])
<div x-show="selectedId !== null" class="w-80 shrink-0">
    <div :style="'top: ' + timelineHeaderOffset + 'px; max-height: calc(100vh - ' + timelineHeaderOffset + 'px)'"
        class="sticky divide-y divide-neutral-200 overflow-y-auto dark:divide-neutral-800">
        <div class="flex items-start justify-between gap-2 p-4">
            <div class="min-w-0">
                <h3 class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400"
                    x-text="selected()?.badge"></h3>
                {{-- started_at, pre-formatted with microseconds (Support\Timeline::stampPreciseTimestamps());
                     ended_at appears only in the duration tooltip. Sized to fit the panel on one line. --}}
                <span class="mt-0.5 block whitespace-nowrap font-mono text-[11px] text-neutral-400 dark:text-neutral-500"
                    x-text="selected()?.metadata?.started_at"></span>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <template x-if="selected()?.type === 'query'">
                    <a :href="selected()?.queryUrl" data-tooltip="{{ __('monitor::messages.common.view_query') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <template x-if="selected()?.type === 'notification'">
                    <a :href="selected()?.notificationUrl" data-tooltip="{{ __('monitor::messages.common.view_notification') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <template x-if="selected()?.type === 'mail'">
                    <a :href="selected()?.mailUrl" data-tooltip="{{ __('monitor::messages.common.view_mail') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <template x-if="selected()?.type === 'exception'">
                    <a :href="selected()?.exceptionUrl" data-tooltip="{{ __('monitor::messages.common.view_exception') }}"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-transparent text-neutral-400 hover:border-neutral-200 hover:bg-white hover:text-emerald-700 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-emerald-200">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::ARROW_UP_RIGHT" :stroke="2" class="h-3 w-3" />
                    </a>
                </template>
                <template x-if="selected()?.type === 'http'">
                    <a :href="selected()?.outgoingUrl" data-tooltip="{{ __('monitor::messages.common.view_outgoing_request') }}"
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

        {{-- SQL, highlighted by layout.blade.php's highlight.js hook. --}}
        <template x-if="selected()?.type === 'query'">
            <div class="p-4">
                <div class="mb-1.5 flex items-center justify-between">
                    <span
                        class="font-mono text-[10px] uppercase tracking-tight text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.sql') }}</span>
                    <button type="button" @click="copySql()"
                        :data-tooltip="sqlCopied ? @js(__('monitor::messages.common.copied')) : @js(__('monitor::messages.common.copy'))"
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
                    {{-- "(90%)" only once the query alone takes a large share of the request. --}}
                    <dd class="font-mono"
                        :class="selected()?.durationShare != null ? 'font-medium text-rose-600 dark:text-rose-400' :
                            'text-neutral-800 dark:text-neutral-200'"
                        :data-tooltip="selected()?.metadata?.ended_at"
                        x-text="formatDuration(selected()?.duration) + (selected()?.durationShare != null ? ' (' + selected().durationShare + ')' : '')"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.calls') }}</dt>
                    {{-- Duplicates append the group's summed time and share: "115 (124.16ms - 60%)". --}}
                    <dd class="font-mono"
                        :class="selected()?.duplicateCount > 1 ? 'font-medium text-amber-600 dark:text-amber-400' :
                            'text-neutral-800 dark:text-neutral-200'"
                        x-text="selected()?.duplicateCount + (selected()?.duplicateTotal != null ? ' (' + formatDuration(selected().duplicateTotal) + (selected().duplicateShare != null ? ' - ' + selected().duplicateShare : '') + ')' : '')"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.connection') }}</dt>
                    <dd class="flex items-center gap-1.5 font-mono text-neutral-800 dark:text-neutral-200">
                        <span x-text="selected()?.metadata?.connection"></span>
                        {{-- PDO connection role from Recorders\Queries; absent before Laravel 12.45. --}}
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
                            style="direction: rtl; text-align: left;" :data-tooltip="selected()?.metadata?.location"
                            x-text="truncatedPath(selected()?.metadata?.location)"></dd>
                    </div>
                </template>
                {{-- Full call stack, when the Queries 'trace' detail option is on. --}}
                <template x-if="selected()?.metadata?.trace">
                    <div class="px-4 py-2.5 text-xs">
                        <dt class="mb-1.5 flex items-center justify-between text-neutral-500 dark:text-neutral-400">
                            <span class="flex items-center gap-2">
                                <span>{{ __('monitor::messages.common.trace') }}</span>
                                <button type="button" @click="showVendor = ! showVendor"
                                    class="rounded border border-neutral-200 px-1.5 py-0.5 text-[10px] text-neutral-500 hover:text-neutral-800 dark:border-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                                    x-text="showVendor ? @js(__('monitor::messages.common.hide_vendor')) : @js(__('monitor::messages.common.show_vendor'))"></button>
                            </span>
                            <button type="button" @click="copyTrace()"
                                :data-tooltip="traceCopied ? @js(__('monitor::messages.common.copied')) : @js(__('monitor::messages.common.copy'))"
                                class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" class="h-3.5 w-3.5" x-show="! traceCopied" />
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="traceCopied" x-cloak />
                            </button>
                        </dt>
                        <dd class="max-h-40 overflow-auto whitespace-pre font-mono text-[11px] leading-relaxed text-neutral-700 dark:text-neutral-300"
                            x-text="visibleTrace()"></dd>
                    </div>
                </template>
            </dl>
        </template>

        <template x-if="selected()?.type === 'cache'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.key') }}</dt>
                    <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                        :data-tooltip="selected()?.metadata?.key" x-text="selected()?.metadata?.key"></dd>
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
                        :data-tooltip="selected()?.metadata?.ended_at" x-text="formatDuration(selected()?.duration)"></dd>
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
                        style="direction: rtl; text-align: left;" :data-tooltip="selected()?.metadata?.notification"
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
                            :data-tooltip="selected()?.metadata?.notifiable"
                            x-text="selected()?.metadata?.notifiable"></dd>
                    </div>
                </template>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        :data-tooltip="selected()?.metadata?.ended_at" x-text="formatDuration(selected()?.duration)"></dd>
                </div>
            </dl>
        </template>

        <template x-if="selected()?.type === 'mail'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.subject') }}</dt>
                    <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                        :data-tooltip="selected()?.metadata?.subject" x-text="selected()?.metadata?.subject"></dd>
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
                        :data-tooltip="selected()?.metadata?.to" x-text="selected()?.metadata?.to"></dd>
                </div>
                <template x-if="selected()?.metadata?.cc">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.cc') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :data-tooltip="selected()?.metadata?.cc" x-text="selected()?.metadata?.cc"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.bcc">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.bcc') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :data-tooltip="selected()?.metadata?.bcc" x-text="selected()?.metadata?.bcc"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.notification">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.via') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :data-tooltip="selected()?.metadata?.notification"
                            x-text="selected()?.metadata?.notification"></dd>
                    </div>
                </template>
                <template x-if="selected()?.metadata?.attachments">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.attachments') }}</dt>
                        <dd class="truncate font-mono text-neutral-800 dark:text-neutral-200"
                            :data-tooltip="mailAttachments()" x-text="mailAttachments()"></dd>
                    </div>
                </template>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        :data-tooltip="selected()?.metadata?.ended_at" x-text="formatDuration(selected()?.duration)"></dd>
                </div>
                <template x-if="mailClass()">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.class') }}</dt>
                        {{-- rtl + text-align:left truncates the front of the
                             FQCN, keeping the class name visible. --}}
                        <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                            style="direction: rtl; text-align: left;" :data-tooltip="mailClass()"
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
                        style="direction: rtl; text-align: left;" :data-tooltip="selected()?.metadata?.model"
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
                        :data-tooltip="selected()?.metadata?.url" x-text="selected()?.metadata?.url"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        :data-tooltip="selected()?.metadata?.ended_at" x-text="formatDuration(selected()?.duration)"></dd>
                </div>
                {{-- Full call stack, when the OutgoingRequests 'trace' detail option is on. --}}
                <template x-if="selected()?.metadata?.trace">
                    <div class="px-4 py-2.5 text-xs">
                        <dt class="mb-1.5 flex items-center justify-between text-neutral-500 dark:text-neutral-400">
                            <span class="flex items-center gap-2">
                                <span>{{ __('monitor::messages.common.trace') }}</span>
                                <button type="button" @click="showVendor = ! showVendor"
                                    class="rounded border border-neutral-200 px-1.5 py-0.5 text-[10px] text-neutral-500 hover:text-neutral-800 dark:border-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                                    x-text="showVendor ? @js(__('monitor::messages.common.hide_vendor')) : @js(__('monitor::messages.common.show_vendor'))"></button>
                            </span>
                            <button type="button" @click="copyTrace()"
                                :data-tooltip="traceCopied ? @js(__('monitor::messages.common.copied')) : @js(__('monitor::messages.common.copy'))"
                                class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" class="h-3.5 w-3.5" x-show="! traceCopied" />
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="traceCopied" x-cloak />
                            </button>
                        </dt>
                        <dd class="max-h-40 overflow-auto whitespace-pre font-mono text-[11px] leading-relaxed text-neutral-700 dark:text-neutral-300"
                            x-text="visibleTrace()"></dd>
                    </div>
                </template>
            </dl>
        </template>

        <template x-if="selected()?.type === 'queue'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.job') }}</dt>
                    {{-- rtl + text-align:left truncates the front of the
                         FQCN, keeping the class name visible. --}}
                    <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                        style="direction: rtl; text-align: left;" :data-tooltip="selected()?.metadata?.key"
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
                {{-- Absent until a job outcome is recorded. --}}
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
                {{-- Where dispatch() was called; dispatch row only. --}}
                <template x-if="selected()?.metadata?.location">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.queued_from') }}</dt>
                        {{-- rtl + text-align:left truncates the front of the
                             path, keeping file:line visible. --}}
                        <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                            style="direction: rtl; text-align: left;" :data-tooltip="selected()?.metadata?.location"
                            x-text="truncatedPath(selected()?.metadata?.location)"></dd>
                    </div>
                </template>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        :data-tooltip="selected()?.metadata?.ended_at" x-text="formatDuration(selected()?.duration)"></dd>
                </div>
                {{-- Full call stack, when the Jobs 'trace' detail option is on; dispatch row only. --}}
                <template x-if="selected()?.metadata?.trace">
                    <div class="px-4 py-2.5 text-xs">
                        <dt class="mb-1.5 flex items-center justify-between text-neutral-500 dark:text-neutral-400">
                            <span class="flex items-center gap-2">
                                <span>{{ __('monitor::messages.common.trace') }}</span>
                                <button type="button" @click="showVendor = ! showVendor"
                                    class="rounded border border-neutral-200 px-1.5 py-0.5 text-[10px] text-neutral-500 hover:text-neutral-800 dark:border-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                                    x-text="showVendor ? @js(__('monitor::messages.common.hide_vendor')) : @js(__('monitor::messages.common.show_vendor'))"></button>
                            </span>
                            <button type="button" @click="copyTrace()"
                                :data-tooltip="traceCopied ? @js(__('monitor::messages.common.copied')) : @js(__('monitor::messages.common.copy'))"
                                class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::COPY" class="h-3.5 w-3.5" x-show="! traceCopied" />
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHECK" :stroke="2" class="h-3.5 w-3.5 text-emerald-500" x-show="traceCopied" x-cloak />
                            </button>
                        </dt>
                        <dd class="max-h-40 overflow-auto whitespace-pre font-mono text-[11px] leading-relaxed text-neutral-700 dark:text-neutral-300"
                            x-text="visibleTrace()"></dd>
                    </div>
                </template>
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
                        style="direction: rtl; text-align: left;" :data-tooltip="selected()?.metadata?.class"
                        x-text="selected()?.metadata?.class"></dd>
                </div>
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.file') }}</dt>
                    <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                        style="direction: rtl; text-align: left;" :data-tooltip="exceptionLocation()"
                        x-text="truncatedPath(exceptionLocation())"></dd>
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

        {{-- A job attempt row: only a duration row (started_at is in the header). --}}
        <template x-if="selected()?.kind === 'attempt'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                    <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.class') }}</dt>
                    <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                        style="direction: rtl; text-align: left;" :data-tooltip="selected()?.label"
                        x-text="selected()?.label"></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    {{-- Tooltip: precise ended_at (an attempt always has a duration). --}}
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        :data-tooltip="selected()?.metadata?.ended_at" x-text="formatDuration(selected()?.duration)"></dd>
                </div>
            </dl>
        </template>

        {{-- A lifecycle phase: duration plus action/render rows (started_at is in the header). --}}
        <template x-if="selected()?.kind === 'phase'">
            <dl class="divide-y divide-neutral-200 dark:divide-neutral-800">
                {{-- start row phase action --}}
                @if ($hasController)
                <template x-if="selected()?.metadata?.controller">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.action') }}</dt>
                        <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                            style="direction: rtl; text-align: left;" :data-tooltip="selected()?.metadata?.controller"
                            x-text="selected()?.metadata?.controller"></dd>
                    </div>
                </template>
                @endif
                {{-- end row phase action --}}
                {{-- start row phase render --}}
                <template x-if="selected()?.metadata?.render">
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 text-xs">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400"
                            x-text="selected()?.metadata?.render?.type === 'view' ? @js(__('monitor::messages.common.render_view')) : @js(__('monitor::messages.common.render_resource'))"></dt>
                        <dd class="min-w-0 truncate font-mono text-neutral-800 dark:text-neutral-200"
                            style="direction: rtl; text-align: left;" :data-tooltip="selected()?.metadata?.render?.name"
                            x-text="selected()?.metadata?.render?.name"></dd>
                    </div>
                </template>
                {{-- end row phase render --}}
                <div class="flex items-center justify-between px-4 py-2.5 text-xs">
                    <dt class="text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                    <dd class="font-mono text-neutral-800 dark:text-neutral-200"
                        :data-tooltip="selected()?.metadata?.ended_at"
                        x-text="formatDuration(selected()?.duration) + (selected()?.metadata?.percent ? ' (' + selected().metadata.percent + ')' : '')"></dd>
                </div>
            </dl>
        </template>
    </div>
</div>
