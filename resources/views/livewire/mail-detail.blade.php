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
@endphp
<div wire:poll.{{ $refresh }}s>
    @if ($entry === null)
        <x-monitor::empty-state :label="__('monitor::messages.nav.mail')" :message="__('monitor::messages.common.mail_not_found')" :period-phrase="$periodPhrase"/>
    @else
        <x-monitor::card class="flex flex-col gap-6 p-4 md:flex-row">
            <div class="md:w-1/2">
                <h3 class="pb-4 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.info') }}</h3>
                <dl class="flex flex-col gap-3">
                    @if ($recipients !== '')
                        <div class="flex max-w-full items-baseline gap-2">
                            <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.recipients') }}</dt>
                            <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                            <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $recipients }}</dd>
                        </div>
                    @endif
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.to') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ $entry->payload['to'] ?? '' }}">{{ $entry->payload['to'] ?? '—' }}</dd>
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
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.attachments') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        @if ($attachmentNames !== [])
                            <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" data-tooltip="{{ implode(', ', $attachmentNames) }}">{{ count($attachmentNames) }} ({{ implode(', ', $attachmentNames) }})</dd>
                        @else
                            <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $entry->payload['attachments'] ?? 0 }}</dd>
                        @endif
                    </div>
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

            <div class="flex flex-col justify-between gap-4 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900 md:w-1/2">
                <div>
                    <h3 class="pb-3 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.subject') }}</h3>
                    <p class="break-words font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $entry->payload['subject'] ?? $entry->key }}</p>
                </div>

                @if ($notification !== null)
                    <a href="{{ route('monitor.notifications.sends.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($notification->key), 'id' => $notification->id] + $range) }}"
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
        </x-monitor::card>
    @endif
</div>
