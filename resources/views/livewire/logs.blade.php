{{-- Livewire tracks only Logs::$oldestId/$newestId for the list; each new batch
     arrives as rendered HTML and these listeners splice it into #monitor-logs-list. --}}
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

    {{-- wire:ignore: only the first paint's rows come from this loop; later
         changes arrive via the monitor-logs-* events. --}}
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
        {{-- Infinite-scroll sentinel calling loadMore(). Kept outside the
             wire:ignore'd list so Livewire removes it once $hasMore is false. --}}
        <div wire:key="logs-load-more-sentinel" x-intersect="$wire.loadMore()"
            class="flex items-center justify-center border-t border-neutral-100 py-3 dark:border-neutral-800">
            {{-- wire:loading.flex: bare wire:loading sets an inline display that overrides `flex`. --}}
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

    {{-- Like Tailwind's animate-ping, but capped at scale(1.1) so the emergency badge
         stays within its row, and held still for the first 75% of each 2s cycle. --}}
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
