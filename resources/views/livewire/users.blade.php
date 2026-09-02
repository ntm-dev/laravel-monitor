@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;

    if (! $embedded) {
        $columns = [
            'name' => ['label' => __('monitor::messages.common.user'), 'align' => 'left'],
            'success' => ['label' => __('monitor::messages.common.ok_status'), 'align' => 'right'],
            'client_errors' => ['label' => __('monitor::messages.common.client_error'), 'align' => 'right'],
            'server_errors' => ['label' => __('monitor::messages.common.server_error'), 'align' => 'right'],
            'requests' => ['label' => __('monitor::messages.nav.requests'), 'align' => 'right'],
            'queued_jobs' => ['label' => __('monitor::messages.common.queued_jobs'), 'align' => 'right'],
            'exceptions' => ['label' => __('monitor::messages.nav.exceptions'), 'align' => 'right'],
            'last_seen' => ['label' => __('monitor::messages.common.last_seen'), 'align' => 'right'],
        ];

        $from = ($page - 1) * $perPage;
        $tz = Format::timezone();
    }
@endphp
{{-- A single, unconditional root element — Livewire finds its component root
     by scanning the rendered HTML for the first tag, so two alternate
     top-level `<div wire:poll>` roots (one per embedded/standalone branch)
     left it unable to reliably identify either: it silently fell back to
     wrapping <x-monitor::section>'s own inner div as the "root" instead,
     which carries no wire:poll at all — the standalone Users tab never
     polled, no matter how long you waited on the page. Keeping the branch
     entirely inside one fixed wrapper is what every other card in this
     package already does. --}}
<div wire:poll.{{ $refresh }}s>
    @if ($embedded)
        <x-monitor::section :icon="Icons::USER" :title="__('monitor::messages.nav.users')">
            <x-slot:actions>
                <x-monitor::link-button :href="route('monitor.dashboard', ['tab' => 'users'] + $range)" external>{{ __('monitor::messages.nav.users') }}</x-monitor::link-button>
            </x-slot:actions>

            <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-3">
                {{-- Users impacted by exceptions --}}
                @if ($impactedUsers->isNotEmpty())
                    <x-monitor::card class="flex flex-col p-4">
                        <x-monitor::badge>{{ __('monitor::messages.nav.exceptions') }}</x-monitor::badge>
                        <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900 dark:text-neutral-100">{{ $impactedUsers->count() }} {{ trans_choice('monitor::messages.common.user_count', $impactedUsers->count()) }} {{ __('monitor::messages.common.impacted_by_exceptions') }} {{ $periodPhrase }}.</p>
                        <div class="mt-4 divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($impactedUsers as $user)
                                <div class="flex items-center gap-2.5 py-2 text-xs">
                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-50 dark:bg-rose-500/10 text-[10px] font-semibold text-rose-600 dark:text-rose-400">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                                    <span class="truncate text-neutral-700 dark:text-neutral-200">{{ $user->name }}</span>
                                    <span class="ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ number_format($user->count) }}×</span>
                                </div>
                            @endforeach
                        </div>
                    </x-monitor::card>
                @else
                    <x-monitor::empty-state :label="__('monitor::messages.nav.exceptions')" :message="__('monitor::messages.common.no_users_impacted_by_exceptions')" :period-phrase="$periodPhrase"/>
                @endif

                {{-- Most active users --}}
                @if ($topUsers->isNotEmpty())
                    <x-monitor::card class="flex flex-col p-4">
                        <x-monitor::badge>{{ __('monitor::messages.nav.requests') }}</x-monitor::badge>
                        <p class="mt-3 max-w-xs text-2xl font-semibold tracking-tight text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.most_active_users') }} {{ $periodPhrase }}.</p>
                        <div class="mt-4 divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($topUsers as $user)
                                <div class="flex items-center gap-2.5 py-2 text-xs">
                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-neutral-100 dark:bg-neutral-800 text-[10px] font-semibold text-neutral-600 dark:text-neutral-300">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                                    <span class="truncate text-neutral-700 dark:text-neutral-200">{{ $user->name }}</span>
                                    <span class="ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ number_format($user->count) }} req</span>
                                </div>
                            @endforeach
                        </div>
                    </x-monitor::card>
                @else
                    <x-monitor::empty-state :label="__('monitor::messages.nav.requests')" :message="__('monitor::messages.common.no_active_users')" :period-phrase="$periodPhrase"/>
                @endif

                {{-- Authenticated users + auth events --}}
                <div class="flex flex-col gap-1.5">
                    <x-monitor::card class="p-4">
                        <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.authenticated_users') }}</p>
                        <p class="mt-1 font-mono text-xl font-semibold leading-none text-neutral-900 dark:text-neutral-100">{{ number_format($authenticatedUsers) }}</p>
                    </x-monitor::card>
                    <x-monitor::card class="flex-1 p-4">
                        <p class="mb-2 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">{{ __('monitor::messages.common.auth_events') }}</p>
                        @if ($authEvents->isEmpty())
                            <p class="py-3 text-xs text-neutral-400 dark:text-neutral-500">{{ __('monitor::messages.common.no_logins_in_period') }}</p>
                        @else
                            <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                @foreach ($authEvents as $event)
                                    <div class="flex items-center gap-2 py-2 text-xs">
                                        <span @class([
                                            'shrink-0 rounded border px-1.5 py-0.5 font-mono text-[10px] uppercase',
                                            'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $event->subtype === 'login',
                                            'border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 text-neutral-500 dark:text-neutral-400' => $event->subtype === 'logout',
                                            'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400' => $event->subtype === 'failed',
                                        ])>{{ $event->subtype }}</span>
                                        <span class="truncate text-neutral-700 dark:text-neutral-200">{{ $event->key }}</span>
                                        <span class="ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ $event->created_at->diffForHumans(short: true) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-monitor::card>
                </div>
            </div>
        </x-monitor::section>
    @else
        <x-monitor::section>
            <x-slot:actions>
                <button type="button" wire:click="$refresh" data-tooltip="{{ __('monitor::messages.common.refresh') }}"
                        class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 shadow-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                    <x-monitor::icon :path="Icons::REFRESH" :stroke="1.8" class="h-3.5 w-3.5"/>
                </button>
            </x-slot:actions>

            {{-- Overview charts --}}
            <div class="grid grid-cols-1 gap-1.5 lg:grid-cols-2"
                 x-data="{
                     hoverIndex: null,
                     setHoverIndex(i) { this.hoverIndex = i },
                     clearHoverIndex() { this.hoverIndex = null },
                 }">
                <x-monitor::cache-chart-card :label="__('monitor::messages.common.authenticated_users')" :total="$authenticatedUsers" :series="[
                    ['key' => 'authenticated', 'label' => __('monitor::messages.common.authenticated_users'), 'dot' => 'bg-emerald-500', 'total' => $authenticatedUsers, 'data' => $authenticatedUserBuckets],
                ]" :since="$since" :until="$until" height="h-[167px]"/>
                <x-monitor::cache-chart-card :label="__('monitor::messages.nav.requests')" :total="$authenticatedRequests + $guestRequests" :series="[
                    ['key' => 'authenticated', 'label' => __('monitor::messages.common.authenticated'), 'dot' => 'bg-emerald-500', 'total' => $authenticatedRequests, 'data' => $authenticatedRequestBuckets],
                    ['key' => 'guest', 'label' => __('monitor::messages.common.guest'), 'dot' => 'bg-orange-500', 'total' => $guestRequests, 'data' => $guestRequestBuckets],
                ]" :since="$since" :until="$until" height="h-[167px]"/>
            </div>

            {{-- User table --}}
            <div class="mt-4 flex items-center justify-between gap-2 px-1 pb-3">
                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($totalUsers) }} {{ trans_choice('monitor::messages.common.user_count', $totalUsers) }}</h3>
            </div>

            @if ($users->isEmpty())
                <x-monitor::empty-state :label="__('monitor::messages.nav.users')" :message="__('monitor::messages.common.no_active_users')" :period-phrase="$periodPhrase"/>
            @else
                <x-monitor::card class="p-4">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800 text-left font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">
                                @foreach ($columns as $field => $column)
                                    <th class="cursor-pointer select-none pb-2 font-normal {{ $column['align'] === 'right' ? 'w-px whitespace-nowrap px-[18px] text-right' : 'text-left' }}"
                                        wire:click="sort('{{ $field }}')">
                                        <span class="inline-flex items-center gap-1 {{ $column['align'] === 'right' ? 'flex-row' : '' }}">
                                            {{ $column['label'] }}
                                            <x-monitor::sort-caret :field="$field" :sort-by="$sortBy" :sort-direction="$sortDirection"/>
                                        </span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody wire:loading.class="hidden" wire:target="previousPage,nextPage" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($users as $user)
                                @php($userUrl = route('monitor.users.show', ['hash' => \LaravelMonitor\Support\KeyHash::for($user->user_id)] + $range))
                                <tr class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" onclick="window.location='{{ $userUrl }}'">
                                    <td class="py-2 pr-2 font-mono text-xs">
                                        <span class="flex items-center gap-2.5">
                                            <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-neutral-100 dark:bg-neutral-800 text-[10px] font-semibold text-neutral-600 dark:text-neutral-300">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                                            <span class="max-w-[20rem] truncate font-mono text-xs text-neutral-700 dark:text-neutral-200">
                                                {{ $user->name }}
                                                <span class="text-neutral-400 dark:text-neutral-500">({{ $user->user_id }})</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td class="w-px whitespace-nowrap px-[18px] py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($user->success) }}</td>
                                    <td class="w-px whitespace-nowrap px-[18px] py-2 text-right font-mono text-xs {{ $user->client_errors > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-300' }}">
                                        <span class="inline-flex items-center justify-end gap-1">
                                            @if ($user->client_errors > 0)
                                                <svg width="10" height="10" viewBox="0 0 10 10" class="size-3! fill-amber-500" xmlns="http://www.w3.org/2000/svg"><path d="M9.87503 7.8287L5.9269 0.549947C5.82953 0.369362 5.68104 0.221523 5.50003 0.124947C5.25411 -0.00665839 4.96617 -0.0358401 4.69884 0.0437494C4.43151 0.123339 4.20642 0.305262 4.07253 0.549947L0.125031 7.8287C0.0387135 7.98887 -0.00444477 8.16875 -0.000203238 8.35066C0.00403829 8.53256 0.0555336 8.71024 0.149223 8.86622C0.242912 9.0222 0.375571 9.15112 0.534164 9.24031C0.692758 9.32951 0.871828 9.3759 1.05378 9.37495H8.94628C9.12068 9.37495 9.2924 9.33202 9.44628 9.24995C9.56819 9.18524 9.67609 9.09703 9.76375 8.99041C9.8514 8.8838 9.91708 8.76088 9.957 8.62876C9.99692 8.49663 10.0103 8.35791 9.99632 8.22059C9.98236 8.08328 9.94072 7.95009 9.87503 7.8287ZM5.00003 8.12495C4.87642 8.12495 4.75558 8.08829 4.6528 8.01962C4.55002 7.95094 4.46991 7.85333 4.42261 7.73912C4.3753 7.62492 4.36292 7.49925 4.38704 7.37802C4.41116 7.25678 4.47068 7.14541 4.55809 7.05801C4.6455 6.9706 4.75686 6.91107 4.8781 6.88696C4.99934 6.86284 5.125 6.87522 5.23921 6.92252C5.35341 6.96983 5.45102 7.04993 5.5197 7.15272C5.58837 7.2555 5.62503 7.37633 5.62503 7.49995C5.62503 7.66571 5.55918 7.82468 5.44197 7.94189C5.32476 8.0591 5.16579 8.12495 5.00003 8.12495ZM5.62503 5.93745C5.62503 6.02033 5.59211 6.09981 5.5335 6.15842C5.4749 6.21702 5.39541 6.24995 5.31253 6.24995H4.68753C4.60465 6.24995 4.52516 6.21702 4.46656 6.15842C4.40795 6.09981 4.37503 6.02033 4.37503 5.93745V3.43745C4.37503 3.35457 4.40795 3.27508 4.46656 3.21648C4.52516 3.15787 4.60465 3.12495 4.68753 3.12495H5.31253C5.39541 3.12495 5.4749 3.15787 5.5335 3.21648C5.59211 3.27508 5.62503 3.35457 5.62503 3.43745V5.93745Z"></path></svg>
                                            @endif
                                            {{ number_format($user->client_errors) }}
                                        </span>
                                    </td>
                                    <td class="w-px whitespace-nowrap px-[18px] py-2 text-right font-mono text-xs {{ $user->server_errors > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-600 dark:text-neutral-300' }}">
                                        <span class="inline-flex items-center justify-end gap-1">
                                            @if ($user->server_errors > 0)
                                                <svg width="10" height="10" viewBox="0 0 10 10" fill="currentColor" class="size-3! fill-rose-500" xmlns="http://www.w3.org/2000/svg"><path d="M9.81687 2.68313L7.31687 0.183125C7.19969 0.0659067 7.04075 3.53984e-05 6.875 0L3.125 0C2.95925 3.53984e-05 2.80031 0.0659067 2.68313 0.183125L0.183125 2.68313C0.0659067 2.80031 3.53984e-05 2.95925 0 3.125L0 6.875C3.53984e-05 7.04075 0.0659067 7.19969 0.183125 7.31687L2.68313 9.81687C2.80031 9.93409 2.95925 9.99996 3.125 10H6.875C7.04075 9.99996 7.19969 9.93409 7.31687 9.81687L9.81687 7.31687C9.93409 7.19969 9.99996 7.04075 10 6.875V3.125C9.99996 2.95925 9.93409 2.80031 9.81687 2.68313ZM5 7.5C4.87639 7.5 4.75555 7.46334 4.65277 7.39467C4.54999 7.32599 4.46988 7.22838 4.42257 7.11418C4.37527 6.99997 4.36289 6.87431 4.38701 6.75307C4.41112 6.63183 4.47065 6.52047 4.55806 6.43306C4.64547 6.34565 4.75683 6.28612 4.87807 6.26201C4.99931 6.23789 5.12497 6.25027 5.23918 6.29757C5.35338 6.34488 5.45099 6.42499 5.51967 6.52777C5.58834 6.63055 5.625 6.75139 5.625 6.875C5.625 7.04076 5.55915 7.19973 5.44194 7.31694C5.32473 7.43415 5.16576 7.5 5 7.5ZM5.625 5.3125C5.625 5.39538 5.59208 5.47487 5.53347 5.53347C5.47487 5.59208 5.39538 5.625 5.3125 5.625H4.6875C4.60462 5.625 4.52513 5.59208 4.46653 5.53347C4.40792 5.47487 4.375 5.39538 4.375 5.3125V2.8125C4.375 2.72962 4.40792 2.65013 4.46653 2.59153C4.52513 2.53292 4.60462 2.5 4.6875 2.5H5.3125C5.39538 2.5 5.47487 2.53292 5.53347 2.59153C5.59208 2.65013 5.625 2.72962 5.625 2.8125V5.3125Z"></path></svg>
                                            @endif
                                            {{ number_format($user->server_errors) }}
                                        </span>
                                    </td>
                                    <td class="w-px whitespace-nowrap px-[18px] py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($user->requests) }}</td>
                                    <td class="w-px whitespace-nowrap px-[18px] py-2 text-right font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ number_format($user->queued_jobs) }}</td>
                                    <td class="w-px whitespace-nowrap px-[18px] py-2 text-right font-mono text-xs {{ $user->exceptions > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-600 dark:text-neutral-300' }}">{{ number_format($user->exceptions) }}</td>
                                    <td class="w-px whitespace-nowrap px-[18px] py-2 text-right font-mono text-xs text-neutral-400 dark:text-neutral-500" data-tooltip="{{ Format::datetime($user->last_seen) }} {{ $tz }}">
                                        <x-monitor::relative-time :at="$user->last_seen"/>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tbody wire:loading.class.remove="hidden" wire:target="previousPage,nextPage" class="hidden animate-pulse divide-y divide-neutral-100 dark:divide-neutral-800">
                            <x-monitor::table-skeleton :columns="8" :rows="count($users)"/>
                        </tbody>
                    </table>

                    @if ($lastPage > 1)
                        <x-monitor::pagination :page="$page" :last-page="$lastPage"
                            :label="__('monitor::messages.common.showing_range', ['from' => $from + 1, 'to' => min($from + $perPage, $totalUsers), 'total' => number_format($totalUsers)])"/>
                    @endif
                </x-monitor::card>
            @endif
        </x-monitor::section>
    @endif
</div>
