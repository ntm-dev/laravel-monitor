{{-- #monitor-logs-list below is wire:ignore'd: Logs::$oldestId/$newestId are
     the only state Livewire tracks for the list, so its wire:snapshot stays
     tiny no matter how far the user has scrolled. Growth happens purely
     client-side — loadMore()/the auto-refresh top-up dispatch just the new
     batch's rendered HTML, and these listeners splice it in directly instead
     of Livewire re-rendering (and re-transferring) the whole accumulated list
     on every request. --}}
<div data-monitor-poll x-data
    x-init="
        $wire.on('monitor-logs-replace', ({ html }) => {
            const list = document.getElementById('monitor-logs-list');
            if (! list) return;
            list.innerHTML = html;
            window.Alpine.initTree(list);
        });
        $wire.on('monitor-logs-append', ({ html }) => {
            const list = document.getElementById('monitor-logs-list');
            if (! list) return;
            const start = list.children.length;
            list.insertAdjacentHTML('beforeend', html);
            for (let i = start; i < list.children.length; i++) window.Alpine.initTree(list.children[i]);
        });
        $wire.on('monitor-logs-prepend', ({ html }) => {
            const list = document.getElementById('monitor-logs-list');
            if (! list) return;
            document.getElementById('monitor-logs-empty')?.remove();
            const before = list.firstElementChild;
            list.insertAdjacentHTML('afterbegin', html);
            for (let node = list.firstElementChild; node && node !== before; node = node.nextElementSibling) window.Alpine.initTree(node);
        });
    ">

    {{-- start log filters --}}
    <div class="flex items-center gap-2">
        <select wire:model.live="level"
            class="h-8 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
            <option value="">{{ __('monitor::messages.common.all_levels') }}</option>
            @foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info'] as $option)
                <option value="{{ $option }}">{{ ucfirst($option) }}</option>
            @endforeach
        </select>
        <x-monitor::user-filter :users="$users"/>
    </div>
    {{-- end log filters --}}

    {{-- Only the very first paint's rows come from this @forelse — Blade
         still computes it on every request, but wire:ignore above means the
         browser only ever applies it once; every later change arrives via
         the monitor-logs-* events instead. --}}
    <div id="monitor-logs-list" wire:ignore class="divide-y divide-neutral-100 dark:divide-neutral-800 mt-1 grid gap-y-1 grid-cols-1">
        @forelse ($logs as $log)
            @include('monitor::livewire.logs-entry', ['log' => $log])
        @empty
            <div id="monitor-logs-empty">
                <x-monitor::empty-state :label="__('monitor::messages.nav.logs')" :message="__('monitor::messages.common.no_log_entries')" :period-phrase="$periodPhrase" />
            </div>
        @endforelse
    </div>

    @if ($hasMore)
        {{-- Infinite-scroll sentinel: enters the viewport once the list is
             scrolled to its end and calls loadMore(), which dispatches the
             next batch's HTML for the x-init listener above to append.
             Deliberately outside #monitor-logs-list (not wire:ignore'd), so
             Livewire can still remove this element itself once storage runs
             dry, without needing a client-side "no more results" guard. --}}
        <div wire:key="logs-load-more-sentinel" x-intersect="$wire.loadMore()"
            class="flex items-center justify-center border-t border-neutral-100 py-3 dark:border-neutral-800">
            {{-- wire:loading.flex (not bare wire:loading): Livewire's
                 default reveal sets display:inline-block inline,
                 which beat the flex class below and stacked the icon
                 and text instead of placing them side by side. --}}
            <span wire:loading.flex wire:target="loadMore" class="items-center gap-2 text-xs text-neutral-400 dark:text-neutral-500">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
                </svg>
                <span>{{ __('monitor::messages.common.loading_more') }}<span x-data="{ dots: 1 }" x-init="setInterval(() => dots = (dots % 3) + 1, 400)" x-text="'.'.repeat(dots)" class="inline-block w-3 text-left"></span></span>
            </span>
        </div>
    @endif

    <x-monitor::scroll-to-top/>

    {{-- Same shape as Tailwind's own animate-ping (fade to 0 while scaling
         up, cubic-bezier(0, 0, 0.2, 1) infinite), but capped at scale(1.2)
         — the default scale(2) blew the emergency badge up past its
         neighbours in the row. Tailwind's own keyframes only define 75%/100%
         (no 0%), so the browser interpolates the *whole* 0%-75% span from
         the base style to that 75% value — a continuous fade, not a pause —
         which pings back-to-back with no rest. The explicit "0%, 75%" stop
         below holds flat at the resting look instead, so the badge sits
         still for 3s (75% of the 4s cycle) between pings, then does the
         actual grow-and-fade in the last 1s (still 75%/100%, matching
         Tailwind's own pacing) before snapping back to rest. --}}
    <style>
        @keyframes monitor-log-emergency-ping {

            0%,
            75% {
                transform: scale(1);
                opacity: 1;
            }

            100% {
                transform: scale(1.1);
                opacity: 0;
            }
        }

        .monitor-log-emergency-ping {
            animation: monitor-log-emergency-ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
        }
    </style>
</div>
