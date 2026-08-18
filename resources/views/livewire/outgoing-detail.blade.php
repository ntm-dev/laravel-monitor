@php
    use LaravelMonitor\Support\Format;

    $fmt = fn ($ms) => Format::duration($ms);
    $tz = Format::timezone();
@endphp
<div wire:poll.{{ $refresh }}s>
    @if ($entry === null)
        <x-monitor::empty-state :label="__('monitor::messages.common.outgoing_request')" :message="__('monitor::messages.common.outgoing_request_not_found')" :period-phrase="$periodPhrase"/>
    @else
        <x-monitor::card class="flex flex-col gap-6 p-4 md:flex-row">
            <div class="md:w-1/2">
                <h3 class="pb-4 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.info') }}</h3>
                <dl class="flex flex-col gap-3">
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.method') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                        <dd class="shrink-0 font-mono text-xs uppercase text-neutral-900 dark:text-white">{{ $entry->payload['method'] ?? '—' }}</dd>
                    </div>
                    <div class="flex max-w-full items-baseline gap-2">
                        <dt class="shrink-0 font-mono text-[11px] uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.status') }}</dt>
                        <div class="relative -bottom-px min-w-6 grow border-b-2 border-dotted border-neutral-400 dark:border-neutral-700"></div>
                        @php($status = $entry->payload['status'] ?? null)
                        <dd class="shrink-0 font-mono text-xs font-medium {{ match (true) {
                                $status === null => 'text-neutral-400 dark:text-neutral-500',
                                $status >= 500 => 'text-rose-600 dark:text-rose-400',
                                $status >= 400 => 'text-amber-600 dark:text-amber-400',
                                default => 'text-emerald-600 dark:text-emerald-400',
                            } }}">{{ $status ?? __('monitor::messages.common.failed') }}</dd>
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

            <div class="flex flex-col gap-4 rounded-2xl bg-neutral-200 shadow-neu dark:bg-neutral-800 dark:shadow-neu-dark p-4 md:w-1/2">
                <div>
                    <h3 class="pb-3 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.url') }}</h3>
                    <p class="break-all font-mono text-xs text-neutral-700 dark:text-neutral-200">{{ $entry->payload['url'] ?? $entry->key }}</p>
                </div>
            </div>
        </x-monitor::card>
    @endif
</div>
