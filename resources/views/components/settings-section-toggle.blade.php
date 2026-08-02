{{-- Collapse/expand toggle for a settings section. Parent must define
     Alpine `open` state (x-data="{ open: ... }") on the enclosing
     x-monitor::section, and toggle its content with x-show="open". --}}
<button type="button" @click.stop="open = !open"
    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md dark:border dark:border-white/10"
    :class="open ? 'text-blue-500 dark:text-emerald-500 dark:bg-white/5' : 'text-neutral-500 dark:bg-white/5'"">
    <x-monitor::chevrons-updown x-show="open" direction="down-up"/>
    <x-monitor::chevrons-updown x-show="! open" x-cloak direction="up-down"/>
</button>
