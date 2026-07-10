<div wire:poll.10s class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <h2 class="font-semibold text-sm mb-3">Mail &amp; notifications</h2>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <p class="text-xs text-gray-500 mb-1.5">Recent mail</p>
            @if ($mails->isEmpty())
                <p class="text-xs text-gray-600 py-3">Nothing sent.</p>
            @else
                <div class="space-y-1.5">
                    @foreach ($mails as $mail)
                        <div class="rounded-lg bg-gray-950/60 border border-gray-800/60 px-2.5 py-1.5 text-xs">
                            <p class="text-gray-300 truncate" title="{{ $mail->payload['subject'] ?? '' }}">{{ $mail->payload['subject'] ?? '(no subject)' }}</p>
                            <p class="text-gray-600 truncate">to {{ $mail->payload['to'] ?? '?' }} · {{ $mail->created_at->diffForHumans(short: true) }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1.5">Notifications</p>
            @if ($notifications->isEmpty())
                <p class="text-xs text-gray-600 py-3">Nothing sent.</p>
            @else
                <div class="space-y-1">
                    @foreach ($notifications as $notification)
                        <div class="flex items-center gap-2 text-xs">
                            <span class="font-mono text-gray-300 truncate" title="{{ $notification->key }}">{{ class_basename($notification->key) }}</span>
                            <span class="ml-auto text-gray-500 shrink-0">{{ number_format($notification->count) }}×</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
