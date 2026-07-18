{{-- Collapse/expand toggle for a settings section. Parent must define
     Alpine `open` state (x-data="{ open: ... }") on the enclosing
     x-monitor::section, and toggle its content with x-show="open". --}}
<button type="button" @click.stop="open = !open"
    class="flex h-6 w-6 items-center justify-center rounded-md border bg-white dark:border-white/8 group-hover:text-emerald-500"
    :class="open ? 'text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/3'">
    <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
        class="lucide lucide-chevrons-up-down" aria-hidden="true">
        <path d="m7 15 5 5 5-5"></path>
        <path d="m7 9 5-5 5 5"></path>
    </svg>
    <svg x-show="open" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
        class="lucide lucide-chevrons-down-up" aria-hidden="true">
        <path d="m7 20 5-5 5 5"></path>
        <path d="m7 4 5 5 5-5"></path>
    </svg>
</button>
