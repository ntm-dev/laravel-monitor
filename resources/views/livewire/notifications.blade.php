<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::NOTIFICATIONS" title="Notifications">
        @if ($notifications->isEmpty())
            <x-monitor::empty-state label="Notifications" message="No notifications sent" :period-phrase="$periodPhrase"/>
        @else
            <div class="grid grid-cols-1 gap-1.5 md:grid-cols-2">
                <x-monitor::card class="p-4">
                    <p class="mb-2 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">By notification</p>
                    <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($notifications as $notification)
                            <div class="flex items-center gap-2 py-2 text-xs">
                                <span class="truncate font-mono text-neutral-700 dark:text-neutral-200" title="{{ $notification->key }}">{{ class_basename($notification->key) }}</span>
                                <span class="ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ number_format($notification->count) }}×</span>
                            </div>
                        @endforeach
                    </div>
                </x-monitor::card>
                <x-monitor::card class="p-4">
                    <p class="mb-2 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Recent</p>
                    <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($recent as $notification)
                            <div class="flex items-center gap-2 py-2 text-xs">
                                <span class="truncate font-mono text-neutral-700 dark:text-neutral-200" title="{{ $notification->key }}">{{ class_basename($notification->key) }}</span>
                                <span class="ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ $notification->created_at->diffForHumans(short: true) }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-monitor::card>
            </div>
        @endif
    </x-monitor::section>
</div>
