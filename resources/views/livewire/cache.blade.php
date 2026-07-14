<div wire:poll.{{ $refresh }}s>
    <x-monitor::section :icon="\LaravelMonitor\Support\Icons::CACHE" title="Cache">
        <div class="grid gap-1.5">
            <x-monitor::card class="grid grid-cols-3 divide-x divide-neutral-100 dark:divide-neutral-800">
                <div class="p-4">
                    <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Hit rate</p>
                    <p class="mt-1 font-mono text-2xl font-semibold leading-none {{ $hitRate !== null && $hitRate < 50 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $hitRate !== null ? $hitRate.'%' : '—' }}</p>
                </div>
                <div class="p-4">
                    <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Hits / Misses</p>
                    <p class="mt-1 font-mono text-2xl font-semibold leading-none text-neutral-900 dark:text-neutral-100">{{ number_format($hits) }} <span class="text-neutral-300 dark:text-neutral-600">/</span> {{ number_format($misses) }}</p>
                </div>
                <div class="p-4">
                    <p class="font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Writes</p>
                    <p class="mt-1 font-mono text-2xl font-semibold leading-none text-neutral-900 dark:text-neutral-100">{{ number_format($writes) }}</p>
                </div>
            </x-monitor::card>

            @if ($keys->isNotEmpty())
                <x-monitor::card class="p-4">
                    <p class="mb-2 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400">Busiest keys</p>
                    <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($keys as $key)
                            <div class="flex items-center gap-2 py-1.5 text-xs">
                                <span class="truncate font-mono text-neutral-700 dark:text-neutral-200" title="{{ $key->key }}">{{ $key->key }}</span>
                                <span class="ml-auto shrink-0 font-mono text-neutral-400 dark:text-neutral-500">{{ number_format($key->count) }}×</span>
                            </div>
                        @endforeach
                    </div>
                </x-monitor::card>
            @endif
        </div>
    </x-monitor::section>
</div>
