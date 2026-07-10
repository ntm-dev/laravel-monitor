<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::USER" title="Users">
        <x-slot:actions>
            <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'users'] + $range)" external>Users</x-monitor::link-button>
        </x-slot:actions>

        <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-3">
            {{-- Users impacted by exceptions --}}
            @if ($impactedUsers->isNotEmpty())
                <x-monitor::card class="flex flex-col p-4">
                    <x-monitor::badge>Exceptions</x-monitor::badge>
                    <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900">{{ $impactedUsers->count() }} {{ $impactedUsers->count() === 1 ? 'user' : 'users' }} impacted by exceptions {{ $periodPhrase }}.</p>
                    <div class="mt-4 divide-y divide-neutral-100">
                        @foreach ($impactedUsers as $user)
                            <div class="flex items-center gap-2.5 py-2 text-xs">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-50 text-[10px] font-semibold text-rose-600">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                                <span class="truncate text-neutral-700">{{ $user->name }}</span>
                                <span class="ml-auto shrink-0 font-mono text-neutral-400">{{ number_format($user->count) }}×</span>
                            </div>
                        @endforeach
                    </div>
                </x-monitor::card>
            @else
                <x-monitor::empty-state label="Exceptions" message="No users impacted by exceptions" :period-phrase="$periodPhrase"/>
            @endif

            {{-- Most active users --}}
            @if ($topUsers->isNotEmpty())
                <x-monitor::card class="flex flex-col p-4">
                    <x-monitor::badge>Requests</x-monitor::badge>
                    <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900">Most active users {{ $periodPhrase }}.</p>
                    <div class="mt-4 divide-y divide-neutral-100">
                        @foreach ($topUsers as $user)
                            <div class="flex items-center gap-2.5 py-2 text-xs">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-[10px] font-semibold text-neutral-600">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                                <span class="truncate text-neutral-700">{{ $user->name }}</span>
                                <span class="ml-auto shrink-0 font-mono text-neutral-400">{{ number_format($user->count) }} req</span>
                            </div>
                        @endforeach
                    </div>
                </x-monitor::card>
            @else
                <x-monitor::empty-state label="Requests" message="No active users" :period-phrase="$periodPhrase"/>
            @endif

            {{-- Authenticated users + auth events --}}
            <div class="flex flex-col gap-1.5">
                <x-monitor::card class="p-4">
                    <p class="font-mono text-xs uppercase tracking-tight text-neutral-500">Authenticated users</p>
                    <p class="mt-1 font-mono text-xl font-semibold leading-none text-neutral-900">{{ number_format($authenticatedUsers) }}</p>
                </x-monitor::card>
                <x-monitor::card class="flex-1 p-4">
                    <p class="mb-2 font-mono text-xs uppercase tracking-tight text-neutral-500">Auth events</p>
                    @if ($authEvents->isEmpty())
                        <p class="py-3 text-xs text-neutral-400">No logins in this period.</p>
                    @else
                        <div class="divide-y divide-neutral-100">
                            @foreach ($authEvents as $event)
                                <div class="flex items-center gap-2 py-2 text-xs">
                                    <span @class([
                                        'shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase',
                                        'border-emerald-200 bg-emerald-50 text-emerald-600' => $event->subtype === 'login',
                                        'border-neutral-200 bg-neutral-50 text-neutral-500' => $event->subtype === 'logout',
                                        'border-rose-200 bg-rose-50 text-rose-600' => $event->subtype === 'failed',
                                    ])>{{ $event->subtype }}</span>
                                    <span class="truncate text-neutral-700">{{ $event->key }}</span>
                                    <span class="ml-auto shrink-0 font-mono text-neutral-400">{{ $event->created_at->diffForHumans(short: true) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-monitor::card>
            </div>
        </div>
    </x-monitor::section>
</div>
