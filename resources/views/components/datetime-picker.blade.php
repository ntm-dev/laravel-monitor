{{-- Custom date-range picker for the header's period bar: trigger, timezone tabs, from/to fields
     (self-drawn month grid + native time input) and apply, all in this component's x-data. --}}
@props(['from', 'to', 'timezoneOffsetMinutes', 'timezoneLabel', 'hasCustomRange', 'currentRouteName', 'currentRouteParams'])
@php
    // min/max are JS expression source spliced into the template, so they can read live x-data state.
    $fields = [
        'from' => ['label' => __('monitor::messages.header.starting_date'), 'min' => 'null', 'max' => 'Date.now()'],
        'to' => ['label' => __('monitor::messages.header.ending_date'), 'min' => 'fromInstantMs !== null ? fromInstantMs + 3600000 : null', 'max' => 'Date.now()'],
    ];
@endphp
{{-- Comments inside this x-data must be Blade comments: it is an HTML attribute, so a double
     quote or a directive-shaped token in a JS comment corrupts it.
     State is fromInstantMs/toInstantMs (epoch ms); the timezone tabs only change the
     wall-clock digits shown and edited. --}}
<div x-data="{
        open: false,
        mode: 'setting',
        localOffsetMinutes: null,
        fromInstantMs: null,
        toInstantMs: null,
        fromDateDigits: null,
        toDateDigits: null,
        fromTime: '',
        toTime: '',
        openCalendar: null,
        {{-- Seeded with the current month in init(): the grid's disabled bindings didn't
             react to a later null-to-value change. --}}
        calendarYear: null,
        calendarMonth: null,
        fromError: '',
        toError: '',
        offsetFor(mode) {
            if (mode === 'utc') { return 0; }
            if (mode === 'local') { return this.localOffsetMinutes; }
            return {{ $timezoneOffsetMinutes }};
        },
        instantToDigits(instantMs, offsetMinutes) {
            const shifted = new Date(instantMs + offsetMinutes * 60000);

            return { Y: shifted.getUTCFullYear(), M: shifted.getUTCMonth(), D: shifted.getUTCDate(), H: shifted.getUTCHours(), Min: shifted.getUTCMinutes() };
        },
        digitsToInstant(digits, offsetMinutes) {
            return Date.UTC(digits.Y, digits.M, digits.D, digits.H, digits.Min) - offsetMinutes * 60000;
        },
        parseRangeString(str) {
            const m = str ? str.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/) : null;

            return m ? { Y: +m[1], M: +m[2] - 1, D: +m[3], H: +m[4], Min: +m[5] } : null;
        },
        formatRangeString(digits) {
            const pad = (n) => String(n).padStart(2, '0');

            return digits.Y + '-' + pad(digits.M + 1) + '-' + pad(digits.D) + 'T' + pad(digits.H) + ':' + pad(digits.Min);
        },
        {{-- Sun-first weekday names and the month label, localized via Intl with the app locale. --}}
        get weekdayLabels() {
            const fmt = new Intl.DateTimeFormat(@js(str_replace('_', '-', app()->getLocale())), { weekday: 'short' });

            return Array.from({ length: 7 }, (_, i) => fmt.format(new Date(Date.UTC(2023, 0, i + 1))));
        },
        get monthLabel() {
            const fmt = new Intl.DateTimeFormat(@js(str_replace('_', '-', app()->getLocale())), { month: 'long', year: 'numeric' });

            return fmt.format(new Date(this.calendarYear, this.calendarMonth, 1));
        },
        init() {
            {{-- Browser offset; negated since getTimezoneOffset() is minutes behind UTC. --}}
            this.localOffsetMinutes = -new Date().getTimezoneOffset();

            {{-- $from/$to are setting-timezone wall clock (Card::normalizeRange()). --}}
            const initialFrom = this.parseRangeString(@js($from));
            const initialTo = this.parseRangeString(@js($to));
            this.fromInstantMs = initialFrom ? this.digitsToInstant(initialFrom, {{ $timezoneOffsetMinutes }}) : null;
            this.toInstantMs = initialTo ? this.digitsToInstant(initialTo, {{ $timezoneOffsetMinutes }}) : null;

            const nowDigits = this.instantToDigits(Date.now(), this.offsetFor(this.mode));
            this.calendarYear = (this.fromInstantMs !== null ? initialFrom : nowDigits).Y;
            this.calendarMonth = (this.fromInstantMs !== null ? initialFrom : nowDigits).M;

            this.$watch('mode', () => this.syncFromInstants());
            this.syncFromInstants();
        },
        openCalendarFor(which) {
            if (this.openCalendar === which) { this.openCalendar = null; return; }

            const base = (which === 'from' ? this.fromDateDigits : this.toDateDigits)
                || this.instantToDigits(Date.now(), this.offsetFor(this.mode));

            this.calendarYear = base.Y;
            this.calendarMonth = base.M;
            this.openCalendar = which;
        },
        prevMonth() {
            this.calendarMonth--;

            if (this.calendarMonth < 0) { this.calendarMonth = 11; this.calendarYear--; }
        },
        nextMonth() {
            this.calendarMonth++;

            if (this.calendarMonth > 11) { this.calendarMonth = 0; this.calendarYear++; }
        },
        {{-- Sun-first grid; spill-over cells carry the adjacent month's days (adjacent: true). --}}
        get calendarWeeks() {
            const firstDow = new Date(this.calendarYear, this.calendarMonth, 1).getDay();
            const daysInMonth = new Date(this.calendarYear, this.calendarMonth + 1, 0).getDate();
            const daysInPrevMonth = new Date(this.calendarYear, this.calendarMonth, 0).getDate();
            const prevYm = this.calendarMonth === 0 ? { Y: this.calendarYear - 1, M: 11 } : { Y: this.calendarYear, M: this.calendarMonth - 1 };
            const nextYm = this.calendarMonth === 11 ? { Y: this.calendarYear + 1, M: 0 } : { Y: this.calendarYear, M: this.calendarMonth + 1 };
            const cells = [];

            for (let i = firstDow - 1; i >= 0; i--) {
                cells.push({ day: daysInPrevMonth - i, month: prevYm.M, year: prevYm.Y, adjacent: true });
            }

            for (let day = 1; day <= daysInMonth; day++) {
                cells.push({ day, month: this.calendarMonth, year: this.calendarYear, adjacent: false });
            }

            for (let day = 1; cells.length % 7 !== 0; day++) {
                cells.push({ day, month: nextYm.M, year: nextYm.Y, adjacent: true });
            }

            const weeks = [];

            for (let i = 0; i < cells.length; i += 7) { weeks.push(cells.slice(i, i + 7)); }

            return weeks;
        },
        {{-- min/max: epoch ms or null. The 1h from/to gap is enforced by the input min and apply(). --}}
        isDayDisabled(cell, min, max) {
            const offset = this.offsetFor(this.mode);
            const startInstant = this.digitsToInstant({ Y: cell.year, M: cell.month, D: cell.day, H: 0, Min: 0 }, offset);
            const endInstant = this.digitsToInstant({ Y: cell.year, M: cell.month, D: cell.day, H: 23, Min: 59 }, offset);

            return (min !== null && endInstant < min) || (max !== null && startInstant > max);
        },
        {{-- Adjacent-month cells are dimmed but clickable, styled apart from disabled cells. --}}
        dayCellClass(cell, min, max, selected) {
            if (this.isDayDisabled(cell, min, max)) { return 'text-neutral-700 cursor-not-allowed'; }

            const isSelected = selected && cell.day === selected.D && cell.month === selected.M && cell.year === selected.Y;

            if (isSelected) { return 'bg-blue-600 text-white hover:bg-blue-600'; }

            return cell.adjacent ? 'text-neutral-600 hover:bg-neutral-700 hover:text-neutral-300' : 'text-neutral-200 hover:bg-neutral-700';
        },
        {{-- Keeps the dropdown open (it also holds the time input) and fills in the current
             time if none is set. An adjacent-month pick moves the grid to that month. --}}
        pickDay(which, cell, min, max) {
            if (this.isDayDisabled(cell, min, max)) { return; }

            const dateDigits = { Y: cell.year, M: cell.month, D: cell.day };

            if (cell.adjacent) { this.calendarYear = cell.year; this.calendarMonth = cell.month; }

            if (which === 'from') {
                this.fromDateDigits = dateDigits;
                if (! this.fromTime) { this.fromTime = this.defaultTime(); }
            } else {
                this.toDateDigits = dateDigits;
                if (! this.toTime) { this.toTime = this.defaultTime(); }
            }

            this.recompute(which);
        },
        defaultTime() {
            const pad = (n) => String(n).padStart(2, '0');
            const d = this.instantToDigits(Date.now(), this.offsetFor(this.mode));

            return pad(d.H) + ':' + pad(d.Min);
        },
        {{-- Disables a month arrow whose target month lies entirely outside min/max. --}}
        nextMonthDisabled(max) {
            if (max === null) { return false; }

            const y = this.calendarMonth === 11 ? this.calendarYear + 1 : this.calendarYear;
            const m = this.calendarMonth === 11 ? 0 : this.calendarMonth + 1;
            const firstDayStart = this.digitsToInstant({ Y: y, M: m, D: 1, H: 0, Min: 0 }, this.offsetFor(this.mode));

            return firstDayStart > max;
        },
        prevMonthDisabled(min) {
            if (min === null) { return false; }

            const y = this.calendarMonth === 0 ? this.calendarYear - 1 : this.calendarYear;
            const m = this.calendarMonth === 0 ? 11 : this.calendarMonth - 1;
            const lastDay = new Date(y, m + 1, 0).getDate();
            const lastDayEnd = this.digitsToInstant({ Y: y, M: m, D: lastDay, H: 23, Min: 59 }, this.offsetFor(this.mode));

            return lastDayEnd < min;
        },
        {{-- datetime-local reports '' for an incomplete or impossible value. A stepper-produced
             one (insertReplacementText) snaps back to the last good value; a typed one is left
             alone for apply() to judge. --}}
        onDatetimeInput(which, event) {
            const parsed = this.parseRangeString(event.target.value);

            if (! parsed) {
                if (event.inputType === 'insertReplacementText') { event.target.value = this.fieldValue(which); }

                return;
            }

            const pad = (n) => String(n).padStart(2, '0');
            const dateDigits = { Y: parsed.Y, M: parsed.M, D: parsed.D };
            const time = pad(parsed.H) + ':' + pad(parsed.Min);

            if (which === 'from') { this.fromDateDigits = dateDigits; this.fromTime = time; } else { this.toDateDigits = dateDigits; this.toTime = time; }

            this.recompute(which);
        },
        {{-- The dropdown's time input; edits the same fromTime/toTime as the main field. --}}
        onTimeChange(which, value) {
            if (which === 'from') { this.fromTime = value; } else { this.toTime = value; }

            this.recompute(which);
        },
        {{-- datetime-local strings for the input's value and min/max (null omits the bound). --}}
        fieldValue(which) {
            const d = which === 'from' ? this.fromDateDigits : this.toDateDigits;
            const t = which === 'from' ? this.fromTime : this.toTime;

            if (! d || ! t) { return ''; }

            const [H, Min] = t.split(':').map(Number);

            return this.formatRangeString({ ...d, H, Min });
        },
        boundAttr(instantMs) {
            return instantMs === null ? null : this.formatRangeString(this.instantToDigits(instantMs, this.offsetFor(this.mode)));
        },
        recompute(which) {
            const dateDigits = which === 'from' ? this.fromDateDigits : this.toDateDigits;
            const time = which === 'from' ? this.fromTime : this.toTime;
            const offset = this.offsetFor(this.mode);
            let instant = null;

            if (dateDigits && time) {
                const [H, Min] = time.split(':').map(Number);
                instant = this.digitsToInstant({ ...dateDigits, H, Min }, offset);
            }

            if (which === 'from') { this.fromInstantMs = instant; } else { this.toInstantMs = instant; }

            this.clampAndSync();
        },
        clampAndSync() {
            if (this.fromInstantMs !== null && this.toInstantMs !== null && this.toInstantMs - this.fromInstantMs < 3600000) {
                this.toInstantMs = this.fromInstantMs + 3600000;
            }

            const now = Date.now();

            if (this.fromInstantMs !== null && this.fromInstantMs > now) { this.fromInstantMs = now; }
            if (this.toInstantMs !== null && this.toInstantMs > now) { this.toInstantMs = now; }

            this.syncFromInstants();
        },
        {{-- Re-derives the digits/time fields from the instants for the active tab's offset. --}}
        syncFromInstants() {
            const offset = this.offsetFor(this.mode);
            const pad = (n) => String(n).padStart(2, '0');

            if (this.fromInstantMs !== null) {
                const d = this.instantToDigits(this.fromInstantMs, offset);
                this.fromDateDigits = { Y: d.Y, M: d.M, D: d.D };
                this.fromTime = pad(d.H) + ':' + pad(d.Min);
            } else {
                this.fromDateDigits = null;
                this.fromTime = '';
            }

            if (this.toInstantMs !== null) {
                const d = this.instantToDigits(this.toInstantMs, offset);
                this.toDateDigits = { Y: d.Y, M: d.M, D: d.D };
                this.toTime = pad(d.H) + ':' + pad(d.Min);
            } else {
                this.toDateDigits = null;
                this.toTime = '';
            }
        },
        {{-- Errors render under their own field. Stages: badInput, empty, future, 1h gap;
             stops at the first stage that fails. --}}
        apply() {
            this.fromError = '';
            this.toError = '';

            {{-- badInput: digits typed that don't form a real date (e.g. 31/02); checked first. --}}
            if (this.$refs.inputFrom.validity.badInput) { this.fromError = @js(__('monitor::messages.header.invalid_date')); }
            if (this.$refs.inputTo.validity.badInput) { this.toError = @js(__('monitor::messages.header.invalid_date')); }
            if (this.fromError || this.toError) { return; }

            if (this.fromInstantMs === null) { this.fromError = @js(__('monitor::messages.header.pick_a_date')); }
            if (this.toInstantMs === null) { this.toError = @js(__('monitor::messages.header.pick_a_date')); }
            if (this.fromError || this.toError) { return; }

            const now = Date.now();

            if (this.fromInstantMs > now) { this.fromError = @js(__('monitor::messages.header.no_future_dates')); }
            if (this.toInstantMs > now) { this.toError = @js(__('monitor::messages.header.no_future_dates')); }
            if (this.fromError || this.toError) { return; }

            {{-- The from/to gap error is shown on "to". --}}
            if (this.toInstantMs - this.fromInstantMs < 3600000) { this.toError = @js(__('monitor::messages.header.end_at_least_1h_after_start')); return; }

            const params = new URLSearchParams({
                from: this.formatRangeString(this.instantToDigits(this.fromInstantMs, {{ $timezoneOffsetMinutes }})),
                to: this.formatRangeString(this.instantToDigits(this.toInstantMs, {{ $timezoneOffsetMinutes }})),
            });
            window.location = '{{ route($currentRouteName, $currentRouteParams) }}?' + params.toString();
        },
        {{-- The setting tab always shows; UTC and Local only when their current offset
             differs from the tabs already shown. --}}
        get showUtcTab() {
            return {{ $timezoneOffsetMinutes }} !== 0;
        },
        get showLocalTab() {
            return this.localOffsetMinutes !== {{ $timezoneOffsetMinutes }} && this.localOffsetMinutes !== 0;
        },
     }" class="relative h-full">
    <button type="button" @click="open = ! open"
            @class([
                'flex h-full items-center gap-1 rounded-md border px-2',
                'border-blue-500 bg-blue-600 text-white' => $hasCustomRange,
                'border-transparent text-neutral-400 hover:text-neutral-900 dark:text-neutral-500 dark:hover:text-neutral-100' => ! $hasCustomRange,
            ])>
        @if ($hasCustomRange)
            <span class="font-mono text-xs">{{ $from }} → {{ $to }}</span>
        @endif
        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CALENDAR" class="h-4 w-4"/>
        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3 w-3"/>
    </button>
    <div x-show="open" x-cloak @click.outside="open = false; openCalendar = null"
         class="absolute right-0 top-full z-30 mt-2 w-64 rounded-lg bg-neutral-900 p-3 shadow-xl shadow-black/20 dark:shadow-md dark:border dark:border-white/20 dark:shadow-white/10">
        {{-- The setting tab always renders, so this row is never empty. --}}
        <div class="flex gap-0.5 rounded-md bg-neutral-800 p-0.5 font-mono text-xs">
            <button type="button" x-show="showUtcTab" @click="mode = 'utc'" class="flex-1 rounded px-2 py-1.5" :class="mode === 'utc' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">{{ __('monitor::messages.header.timezone_utc') }}</button>
            <button type="button" x-show="showLocalTab" @click="mode = 'local'" class="flex-1 rounded px-2 py-1.5" :class="mode === 'local' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">{{ __('monitor::messages.header.timezone_local') }}</button>
            {{-- An overflowing label bounce-scrolls (.monitor-marquee in layout.blade.php).
                 x-effect reads `open` so it re-measures each time the popover becomes visible. --}}
            <button type="button" @click="mode = 'setting'" data-tooltip="{{ $timezoneLabel }}"
                    x-effect="
                        if (open) {
                            $nextTick(() => {
                                const over = $refs.label.scrollWidth - $el.clientWidth;
                                $refs.label.classList.toggle('monitor-marquee', over > 0);
                                if (over > 0) {
                                    $refs.label.style.setProperty('--monitor-marquee-distance', (-over) + 'px');
                                }
                            });
                        }
                    "
                    class="min-w-0 flex-1 overflow-hidden rounded px-2 py-1.5" :class="mode === 'setting' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">
                <span x-ref="label" class="inline-block whitespace-nowrap">{{ $timezoneLabel }}</span>
            </button>
        </div>
        {{-- One date+time field per row; its calendar icon opens a dropdown with the date grid and a time input. --}}
        @foreach ($fields as $which => $field)
            <div>
                <label class="mt-3 block text-xs text-neutral-400">{{ $field['label'] }}</label>
                <div class="relative mt-1">
                    {{-- An invalid typed value stays on screen; apply() uses validity.badInput to tell
                         an impossible date from an empty field. --}}
                    <input type="datetime-local" x-ref="input{{ ucfirst($which) }}" :value="fieldValue('{{ $which }}')" @input="onDatetimeInput('{{ $which }}', $event)"
                           :min="boundAttr({{ $field['min'] }})" :max="boundAttr({{ $field['max'] }})" style="color-scheme: dark"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-800 py-1.5 pl-2 pr-8 font-mono text-xs text-neutral-200 focus:outline-none [&::-webkit-calendar-picker-indicator]:hidden">
                    <button type="button" @click="openCalendarFor('{{ $which }}')"
                            class="absolute right-2 top-1/2 flex -translate-y-1/2 items-center text-neutral-500 hover:text-neutral-300">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CALENDAR" class="h-3.5 w-3.5"/>
                    </button>
                    <div x-show="openCalendar === '{{ $which }}'" x-cloak @click.outside="openCalendar = null"
                         class="absolute left-0 top-full z-40 mt-1 w-64 rounded-lg border border-neutral-700 bg-neutral-900 p-2 shadow-xl shadow-black/20"
                         style="color-scheme: dark">
                        <div class="flex items-center justify-between px-1 pb-2">
                            <button type="button" :disabled="prevMonthDisabled({{ $field['min'] }})" @click="if (! prevMonthDisabled({{ $field['min'] }})) { prevMonth(); }"
                                    class="flex h-6 w-6 items-center justify-center rounded text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-700 disabled:hover:bg-transparent">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3.5 w-3.5 rotate-90"/>
                            </button>
                            <span class="font-mono text-xs capitalize text-neutral-200" x-text="monthLabel"></span>
                            <button type="button" :disabled="nextMonthDisabled({{ $field['max'] }})" @click="if (! nextMonthDisabled({{ $field['max'] }})) { nextMonth(); }"
                                    class="flex h-6 w-6 items-center justify-center rounded text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-700 disabled:hover:bg-transparent">
                                <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CHEVRON_DOWN" :stroke="2" class="h-3.5 w-3.5 -rotate-90"/>
                            </button>
                        </div>
                        <div class="grid grid-cols-7 gap-0.5 px-1 pb-1 font-mono text-[10px] uppercase text-neutral-500">
                            <template x-for="label in weekdayLabels" :key="label">
                                <span class="flex h-6 items-center justify-center" x-text="label"></span>
                            </template>
                        </div>
                        {{-- Keep a single button per cell: x-for needs exactly one root element. --}}
                        <template x-for="(week, wi) in calendarWeeks" :key="wi">
                            <div class="grid grid-cols-7 gap-0.5 px-1">
                                <template x-for="(cell, di) in week" :key="di">
                                    <button type="button" :disabled="isDayDisabled(cell, {{ $field['min'] }}, {{ $field['max'] }})"
                                            @click="pickDay('{{ $which }}', cell, {{ $field['min'] }}, {{ $field['max'] }})" x-text="cell.day"
                                            class="flex h-7 items-center justify-center rounded font-mono text-xs"
                                            :class="dayCellClass(cell, {{ $field['min'] }}, {{ $field['max'] }}, {{ $which }}DateDigits)"></button>
                                </template>
                            </div>
                        </template>
                        {{-- Same time as the main field's segments; color-scheme keeps its clock icon visible. --}}
                        <div class="mt-2 border-t border-neutral-800 px-1 pt-2">
                            <label class="block font-mono text-[10px] uppercase text-neutral-500">{{ __('monitor::messages.header.time') }}</label>
                            <input type="time" :value="{{ $which }}Time" @input="onTimeChange('{{ $which }}', $event.target.value)"
                                   class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-800 px-2 py-1.5 font-mono text-xs text-neutral-200 focus:outline-none">
                        </div>
                    </div>
                </div>
                <p x-show="{{ $which }}Error" x-text="{{ $which }}Error" class="mt-1 text-xs text-rose-400"></p>
            </div>
        @endforeach
        <button type="button" @click="apply()"
                class="mt-3 w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">{{ __('monitor::messages.header.apply') }}</button>
    </div>
</div>
