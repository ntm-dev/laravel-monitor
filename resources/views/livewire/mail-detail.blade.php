@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();

    $recipients = $entry !== null ? collect([
        'TO' => $entry->payload['to_count'] ?? 0,
        'CC' => $entry->payload['cc_count'] ?? 0,
        'BCC' => $entry->payload['bcc_count'] ?? 0,
    ])->filter(fn ($count, $label) => $count > 0 || $label === 'TO')
        ->map(fn ($count, $label) => $count.' '.$label)
        ->implode(' / ') : '';

    $attachmentNames = $entry !== null ? ($entry->payload['attachment_names'] ?? []) : [];

    // The right-hand panel only ever holds the "sent via notification"
    // banner now that Subject lives in the left info list below — skip it
    // entirely rather than leave an empty bordered box when there's nothing
    // to show there.
    $hasSidePanel = $entry !== null && ($notification !== null || filled($entry->payload['notification'] ?? null));
@endphp
<div>
    @if ($entry === null)
        <x-monitor::empty-state :label="__('monitor::messages.nav.mail')" :message="__('monitor::messages.common.mail_not_found')" :period-phrase="$periodPhrase"/>
    @else
        <x-monitor::card class="flex flex-col gap-6 p-4 md:flex-row">
            <div class="{{ $hasSidePanel ? 'md:w-1/2' : 'w-full' }}">
                <div class="flex items-center justify-between pb-4">
                    <h3 class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.info') }}</h3>
                    <button type="button" wire:click="downloadEml"
                            class="flex items-center gap-1.5 rounded-md border border-neutral-200 px-2 py-1 font-mono text-[11px] text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">
                        <x-monitor::icon :path="Icons::DOWNLOAD" :stroke="1.8" class="h-3.5 w-3.5"/>
                        {{ __('monitor::messages.common.download_eml') }}
                    </button>
                </div>
                <dl class="flex flex-col gap-3">
                    @if ($recipients !== '')
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.recipients') }}</dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $recipients }}</dd>
                        </div>
                    @endif
                    @if (filled($entry->payload['from'] ?? null))
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.from') }}</dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ $entry->payload['from'] }}">{{ $entry->payload['from'] }}</dd>
                        </div>
                    @endif
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.to') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ $entry->payload['to'] ?? '' }}">{{ $entry->payload['to'] ?? '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.subject') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ $entry->payload['subject'] ?? $entry->key }}">{{ $entry->payload['subject'] ?? $entry->key }}</dd>
                    </div>
                    @if (filled($entry->payload['cc'] ?? null))
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.cc') }}</dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ $entry->payload['cc'] }}">{{ $entry->payload['cc'] }}</dd>
                        </div>
                    @endif
                    @if (filled($entry->payload['bcc'] ?? null))
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.bcc') }}</dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ $entry->payload['bcc'] }}">{{ $entry->payload['bcc'] }}</dd>
                        </div>
                    @endif
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.mailer') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $entry->payload['mailer'] ?? '—' }}</dd>
                    </div>
                    @if (filled($entry->payload['mailable'] ?? null))
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.mailable') }}</dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ $entry->payload['mailable'] }}">{{ $entry->payload['mailable'] }}</dd>
                        </div>
                    @endif
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.attachments') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        @if ($attachmentNames !== [])
                            <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ implode(', ', $attachmentNames) }}">{{ count($attachmentNames) }} ({{ implode(', ', $attachmentNames) }})</dd>
                        @else
                            <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $entry->payload['attachments'] ?? 0 }}</dd>
                        @endif
                    </div>
                    @if (filled($entry->payload['server'] ?? null))
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.server') }}</dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $entry->payload['server'] }}</dd>
                        </div>
                    @endif
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $entry->duration !== null ? $fmt($entry->duration) : '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.sent_at') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></dd>
                    </div>
                </dl>
            </div>

            @if ($hasSidePanel)
                <div class="flex flex-col justify-between gap-4 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900 md:w-1/2">
                    @if ($notification !== null)
                        <a href="{{ route('monitor.notifications.sends.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($notification->key), 'id' => \LaravelMonitor\Support\EntryId::encode($notification->id)] + $range) }}"
                           class="flex items-center justify-between gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2.5 text-sm font-medium text-blue-700 hover:bg-blue-100 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:bg-blue-500/20">
                            <span class="flex items-center gap-2">
                                <x-monitor::icon :path="Icons::NOTIFICATIONS" class="h-4 w-4"/>
                                {{ __('monitor::messages.common.sent_via_notification', ['name' => $notification->payload['notification'] ?? $notification->key]) }}
                            </span>
                            <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-4 w-4"/>
                        </a>
                    @elseif (filled($entry->payload['notification'] ?? null))
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.sent_via_notification_missing', ['name' => $entry->payload['notification']]) }}</p>
                    @endif
                </div>
            @endif
        </x-monitor::card>

        {{-- start card mail body --}}
        <x-monitor::card class="mt-4 p-4">
            <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('monitor::messages.common.mail_body') }}
                @if (filled($entry->payload['body_format'] ?? null))
                    <span class="font-mono text-xs font-normal text-neutral-400 dark:text-neutral-500">({{ __('monitor::messages.common.mail_body_format_'.$entry->payload['body_format']) }})</span>
                @endif
            </h2>
            @if (filled($entry->payload['body'] ?? null) && ($entry->payload['body_format'] ?? null) === 'html')
                {{-- sandbox with no `allow-*` tokens: a real HTML/CSS preview
                     with every script/form/same-origin access blocked — the
                     recorded body is untrusted markup, never safe via {!! !!}. --}}
                <iframe srcdoc="{{ $entry->payload['body'] }}" sandbox=""
                        class="h-[32rem] w-full rounded-md border border-neutral-100 bg-white dark:border-white/5"
                        title="{{ __('monitor::messages.common.mail_body') }}"></iframe>
            @elseif (filled($entry->payload['body'] ?? null))
                {{-- Plain-text body, preformatted like a mail client would —
                     {{ }} already escapes it, so this is safe as-is. --}}
                <pre class="h-[32rem] overflow-auto rounded-md border border-neutral-100 bg-white p-3 font-mono text-[11px] leading-relaxed text-neutral-700 dark:border-white/5 dark:bg-neutral-950 dark:text-neutral-200"><code>{{ $entry->payload['body'] }}</code></pre>
            @else
                <p class="text-sm text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.mail_body_not_recorded') }}</p>
            @endif
        </x-monitor::card>
        {{-- end card mail body --}}
    @endif
</div>
