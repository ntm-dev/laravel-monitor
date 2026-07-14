<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::MAIL" title="Mail">
        @if ($mails->isEmpty())
            <x-monitor::empty-state label="Mail" message="No mail sent" :period-phrase="$periodPhrase"/>
        @else
            <x-monitor::card>
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($mails as $mail)
                        <div class="px-3.5 py-2.5 text-xs">
                            <p class="truncate text-neutral-700 dark:text-neutral-200" title="{{ $mail->payload['subject'] ?? '' }}">{{ $mail->payload['subject'] ?? '(no subject)' }}</p>
                            <p class="mt-0.5 truncate font-mono text-[11px] text-neutral-400 dark:text-neutral-500">to {{ $mail->payload['to'] ?? '?' }} · {{ $mail->created_at->diffForHumans(short: true) }}</p>
                        </div>
                    @endforeach
                </div>
            </x-monitor::card>
        @endif
    </x-monitor::section>
</div>
