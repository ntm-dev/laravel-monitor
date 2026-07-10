<div wire:poll.10s class="bg-night-900 border border-night-700/60 rounded-xl p-4">
    <h2 class="font-semibold text-sm mb-3">Users</h2>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <p class="text-xs text-gray-500 mb-1.5">Most active (by requests)</p>
            @if ($topUsers->isEmpty())
                <p class="text-xs text-gray-600 py-3">No authenticated traffic.</p>
            @else
                <div class="space-y-1">
                    @foreach ($topUsers as $user)
                        <div class="flex items-center gap-2 text-xs">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-violet-500/20 text-violet-300 text-[10px] font-semibold shrink-0">
                                {{ strtoupper(mb_substr($user->name, 0, 1)) }}
                            </span>
                            <span class="text-gray-300 truncate">{{ $user->name }}</span>
                            <span class="ml-auto text-gray-500 shrink-0">{{ number_format($user->count) }} req</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1.5">Recent auth events</p>
            @if ($authEvents->isEmpty())
                <p class="text-xs text-gray-600 py-3">No logins in this period.</p>
            @else
                <div class="space-y-1">
                    @foreach ($authEvents as $event)
                        <div class="flex items-center gap-2 text-xs">
                            <span @class([
                                'shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase',
                                'bg-emerald-500/10 text-emerald-400' => $event->subtype === 'login',
                                'bg-gray-500/10 text-gray-400' => $event->subtype === 'logout',
                                'bg-red-500/10 text-red-400' => $event->subtype === 'failed',
                            ])>{{ $event->subtype }}</span>
                            <span class="text-gray-300 truncate">{{ $event->key }}</span>
                            <span class="ml-auto text-gray-600 shrink-0">{{ $event->created_at->diffForHumans(short: true) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
