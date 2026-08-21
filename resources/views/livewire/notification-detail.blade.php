@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();
@endphp
<div wire:poll.{{ $refresh }}s>
    @if ($entry === null)
        <x-monitor::empty-state :label="__('monitor::messages.common.notification')" :message="__('monitor::messages.common.notification_not_found')" :period-phrase="$periodPhrase"/>
    @else
        <x-monitor::card class="flex flex-col gap-6 p-4 md:flex-row">
            <div class="md:w-1/2">
                <h3 class="pb-4 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.info') }}</h3>
                <dl class="flex flex-col gap-3">
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.notification') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                        <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" title="{{ $entry->payload['notification'] ?? $entry->key }}">{{ $entry->payload['notification'] ?? $entry->key }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.channel') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                        <dd class="shrink-0 font-mono text-xs uppercase text-neutral-900 dark:text-white">{{ $entry->subtype ?? $entry->payload['channel'] ?? '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.notifiable') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                        <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" title="{{ $entry->payload['notifiable'] ?? '' }}">{{ $entry->payload['notifiable'] ?? '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.duration') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                        <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $entry->duration !== null ? $fmt($entry->duration) : '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.sent_at') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                        <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></dd>
                    </div>
                </dl>
            </div>

            <div class="flex flex-col justify-between gap-4 rounded-2xl bg-neutral-200 shadow-neu dark:bg-neutral-800 dark:shadow-neu-dark p-4 md:w-1/2">
                <div>
                    <h3 class="pb-3 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.notification_class') }}</h3>
                    <p class="break-all font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $entry->payload['notification'] ?? $entry->key }}</p>
                </div>

                @if ($mail !== null)
                    <a href="{{ route('monitor.mail.sends.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($mail->key), 'id' => $mail->id] + $range) }}"
                       class="flex items-center justify-between gap-2 rounded-xl bg-neutral-200 px-3 py-2.5 text-sm font-medium text-blue-700 shadow-neu-sm hover:shadow-neu dark:bg-neutral-800 dark:text-purple-400 dark:shadow-neu-dark-sm dark:hover:shadow-neu-dark">
                        <span class="flex items-center gap-2">
                            <x-monitor::icon :path="Icons::MAIL" class="h-4 w-4"/>
                            {{ __('monitor::messages.common.view_sent_email') }}
                        </span>
                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-4 w-4"/>
                    </a>
                @elseif (($entry->subtype ?? null) === 'mail')
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.email_entry_not_found') }}</p>
                @endif
            </div>
        </x-monitor::card>
    @endif
</div>
