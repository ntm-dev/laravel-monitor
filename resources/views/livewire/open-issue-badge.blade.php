<span wire:poll.{{ $refresh }}s>
    @if ($count > 0)
        <span class="shrink-0 rounded-full border border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 px-1.5 py-0.5 font-mono text-[10px] leading-none text-rose-600 dark:text-rose-400">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</span>
