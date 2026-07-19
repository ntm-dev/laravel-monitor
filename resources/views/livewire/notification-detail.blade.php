@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();
@endphp
<div wire:poll.{{ $refresh }}s>
    @if ($entry === null)
        <x-monitor::empty-state label="Notification" message="This notification could not be found — it may have been purged." :period-phrase="$periodPhrase"/>
    @else
        <x-monitor::card class="flex flex-col gap-6 p-4 md:flex-row">
            <div class="md:w-1/2">
                <h3 class="pb-4 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Info</h3>
                <dl class="flex flex-col gap-3">
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Notification</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" title="{{ $entry->payload['notification'] ?? $entry->key }}">{{ class_basename($entry->payload['notification'] ?? $entry->key) }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Channel</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="shrink-0 font-mono text-xs uppercase text-neutral-900 dark:text-white">{{ $entry->subtype ?? $entry->payload['channel'] ?? '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Notifiable</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="max-w-[60%] truncate font-mono text-xs text-neutral-900 dark:text-white" title="{{ $entry->payload['notifiable'] ?? '' }}">{{ $entry->payload['notifiable'] ?? '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Duration</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ $entry->duration !== null ? $fmt($entry->duration) : '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Sent at</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-300 dark:border-white/20"></div>
                        <dd class="shrink-0 font-mono text-xs text-neutral-900 dark:text-white">{{ Format::datetime($entry->created_at) }} <span class="text-neutral-300 dark:text-neutral-600">{{ $tz }}</span></dd>
                    </div>
                </dl>
            </div>

            <div class="flex flex-col justify-between gap-4 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900 md:w-1/2">
                <div>
                    <h3 class="pb-3 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Notification class</h3>
                    <p class="break-all font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $entry->payload['notification'] ?? $entry->key }}</p>
                </div>

                @if ($mail !== null)
                    <a href="{{ route('monitor.dashboard', ['tab' => 'mail', 'key' => $mail->id] + $range) }}"
                       class="flex items-center justify-between gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2.5 text-sm font-medium text-blue-700 hover:bg-blue-100 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:bg-blue-500/20">
                        <span class="flex items-center gap-2">
                            <x-monitor::icon :path="Icons::MAIL" class="h-4 w-4"/>
                            View sent email
                        </span>
                        <x-monitor::icon :path="Icons::ARROW_UP_RIGHT" :stroke="2" class="h-4 w-4"/>
                    </a>
                @elseif (($entry->subtype ?? null) === 'mail')
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">The corresponding email entry could not be found — it may have been purged.</p>
                @endif
            </div>
        </x-monitor::card>
    @endif
</div>
