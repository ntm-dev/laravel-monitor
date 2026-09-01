{{-- Self-contained date+time picker: label, a real native
     `<input type="datetime-local">` as the trigger/value field (so each
     segment — day/month/year/hour/minute — is directly editable by typing
     or the arrow keys), and a dropdown with a self-drawn month grid plus a
     time input for point-and-click selection. Reused for both the "from"
     and "to" fields in components/header.blade.php's custom-range popover.

     This is a template partial, not its own Alpine scope (no x-data here) —
     it reads/calls the enclosing x-data's state and methods (mode,
     calendarYear, calendarMonth, fromDateDigits/toDateDigits, fromTime/
     toTime, offsetFor(), fieldValue(), onDatetimeInput(), onTimeChange(),
     boundAttr(), weekdayLabels, monthLabel, prevMonth()/nextMonth()) exactly
     like the calendar template it replaces used to.

     min/max are plain literal JS expression source, not PHP values — spliced
     in unescaped below, the same way {{ $which }} is spliced into quoted JS
     string literals elsewhere in this component and in header.blade.php's
     own x-data. That is what lets a caller parameterize the bounds per
     instance (e.g. min="null" for the "from" field, min="fromInstantMs !==
     null ? fromInstantMs + 3600000 : null" for the "to" field) without this
     component needing to know anything about where those bounds come from. --}}
@props(['which', 'label' => null, 'min' => 'null', 'max' => 'null'])
<div>
    @if ($label !== null)
        <label class="mt-3 block text-xs text-neutral-400">{{ $label }}</label>
    @endif
    <div class="relative mt-1">
        {{-- The native input's own arrow-key stepper has no way to skip an
             impossible day/month combination (e.g. day 31 held, stepping the
             month up from January to February, which has no 31st) — there is
             no API to intervene in its internal stepping logic from JS, only
             to react after the fact. onDatetimeInput below snaps a stepper-
             produced invalid combination back to the last known-good value
             immediately (detected via the input event's own inputType); a
             still-incomplete typed segment is left alone there and fixed up
             on blur instead, so normal digit-by-digit typing isn't disrupted
             mid-keystroke. --}}
        <input type="datetime-local" :value="fieldValue('{{ $which }}')" @input="onDatetimeInput('{{ $which }}', $event)"
               @blur="$event.target.value = fieldValue('{{ $which }}')"
               :min="boundAttr({{ $min }})" :max="boundAttr({{ $max }})" style="color-scheme: dark"
               class="w-full rounded-md border border-neutral-700 bg-neutral-800 py-1.5 pl-2 pr-8 font-mono text-xs text-neutral-200 focus:outline-none [&::-webkit-calendar-picker-indicator]:hidden">
        <button type="button" @click="openCalendarFor('{{ $which }}')"
                class="absolute right-2 top-1/2 flex -translate-y-1/2 items-center text-neutral-500 hover:text-neutral-300">
            <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CALENDAR" class="h-3.5 w-3.5"/>
        </button>
        <div x-show="openCalendar === '{{ $which }}'" x-cloak @click.outside="openCalendar = null"
             class="absolute left-0 top-full z-40 mt-1 w-64 rounded-lg border border-neutral-700 bg-neutral-900 p-2 shadow-xl shadow-black/20"
             style="color-scheme: dark">
            <div class="flex items-center justify-between px-1 pb-2">
                <button type="button" :disabled="prevMonthDisabled({{ $min }})" @click="if (! prevMonthDisabled({{ $min }})) { prevMonth(); }"
                        class="flex h-6 w-6 items-center justify-center rounded text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-700 disabled:hover:bg-transparent">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3.5 w-3.5 rotate-90"/>
                </button>
                <span class="font-mono text-xs capitalize text-neutral-200" x-text="monthLabel"></span>
                <button type="button" :disabled="nextMonthDisabled({{ $max }})" @click="if (! nextMonthDisabled({{ $max }})) { nextMonth(); }"
                        class="flex h-6 w-6 items-center justify-center rounded text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-700 disabled:hover:bg-transparent">
                    <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3.5 w-3.5 -rotate-90"/>
                </button>
            </div>
            <div class="grid grid-cols-7 gap-0.5 px-1 pb-1 font-mono text-[10px] uppercase text-neutral-500">
                <template x-for="label in weekdayLabels" :key="label">
                    <span class="flex h-6 items-center justify-center" x-text="label"></span>
                </template>
            </div>
            {{-- Every cell always renders a real day number, including the
                 leading/trailing days that belong to the adjacent month —
                 those are just dimmed (still clickable: picking one slides
                 the visible month to that day's own month), not blanked out.
                 A single <button> per cell (branching only its class/disabled
                 state), not two sibling <template x-if> branches — Alpine's
                 x-for requires its immediate template child to be exactly one
                 root element, and splitting this into two elements silently
                 corrupted the whole grid's reactivity once already. --}}
            <template x-for="(week, wi) in calendarWeeks" :key="wi">
                <div class="grid grid-cols-7 gap-0.5 px-1">
                    <template x-for="(cell, di) in week" :key="di">
                        <button type="button" :disabled="isDayDisabled(cell, {{ $min }}, {{ $max }})"
                                @click="pickDay('{{ $which }}', cell, {{ $min }}, {{ $max }})" x-text="cell.day"
                                class="flex h-7 items-center justify-center rounded font-mono text-xs"
                                :class="dayCellClass(cell, {{ $min }}, {{ $max }}, {{ $which }}DateDigits)"></button>
                    </template>
                </div>
            </template>
            {{-- A second way to set the same time the main field's own Hour/
                 Minute segments also edit — the dropdown is meant to offer
                 date and time together in one place, matching Laravel
                 Nightwatch's own combined date+time dropdown, not just the
                 date grid on its own. color-scheme is set on this dropdown
                 so the native time input's own clock-icon chrome draws
                 light-on-dark instead of a near-invisible dark glyph. --}}
            <div class="mt-2 border-t border-neutral-800 px-1 pt-2">
                <label class="block font-mono text-[10px] uppercase text-neutral-500">{{ __('monitor::messages.header.time') }}</label>
                <input type="time" :value="{{ $which }}Time" @input="onTimeChange('{{ $which }}', $event.target.value)"
                       class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-800 px-2 py-1.5 font-mono text-xs text-neutral-200 focus:outline-none">
            </div>
        </div>
    </div>
</div>
