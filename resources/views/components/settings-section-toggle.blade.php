{{-- Collapse/expand toggle for a settings section. Parent must define
     Alpine `open` state (x-data="{ open: ... }") on the enclosing
     x-monitor::section, and toggle its content with x-show="open". --}}
<button type="button" @click.stop="open = !open"
    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-neutral-200 dark:bg-neutral-800"
    :class="open ? 'text-blue-500 shadow-neu-inset dark:shadow-neu-dark-inset' : 'text-neutral-500 shadow-neu-sm dark:shadow-neu-dark-sm'">
    <x-monitor::chevrons-updown x-show="open" direction="down-up"/>
    <x-monitor::chevrons-updown x-show="! open" x-cloak direction="up-down"/>
</button>
