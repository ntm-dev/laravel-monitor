{{-- Custom date-range picker: the trigger button (rendered inside
     components/header.blade.php's period-switcher bar), the UTC/Local/
     setting timezone tabs, the "from"/"to" datetime fields (self-drawn
     month grid + native time input each), and the apply button — all
     self-contained in this single component's own x-data. header.blade.php
     just renders <x-monitor::datetime-picker/> once and passes through the
     range/timezone props it already has; nothing about the picker's state
     or behavior lives in header.blade.php itself. --}}
@props(['from', 'to', 'timezoneOffsetMinutes', 'timezoneLabel', 'hasCustomRange', 'currentRouteName', 'currentRouteParams'])
@php
    // min/max are plain literal JS expression source, not PHP values —
    // spliced in unescaped below, so a field's bound can reference this
    // x-data's own live state (e.g. "to"'s min following wherever "from"
    // is currently set) rather than being frozen at render time.
    $fields = [
        'from' => ['label' => __('monitor::messages.header.starting_date'), 'min' => 'null', 'max' => 'Date.now()'],
        'to' => ['label' => __('monitor::messages.header.ending_date'), 'min' => 'fromInstantMs !== null ? fromInstantMs + 3600000 : null', 'max' => 'Date.now()'],
    ];
@endphp
{{-- Comments inside this x-data live in an HTML attribute, not a
     script tag — a literal double-quote character or a Blade
     directive keyword written out either corrupts the attribute
     or gets compiled as a real directive (bit us once already,
     leaking surrounding JS out as visible page text). Blade's
     own double-brace comment style is used for these below,
     not slash-slash, precisely because it's deleted wholesale
     at compile time — the content never reaches the browser,
     so nothing inside it can corrupt anything.

     The date grid below is hand-rolled (plain Alpine, no CDN
     library) — a Flatpickr-based version was tried first, but
     brought real integration cost of its own (a circular-object-
     in-a-reactive-proxy stack overflow, its calendar drifting
     from the input on page scroll, a mismatched color theme) and
     its time-of-day widget turned out not to genuinely restrict
     anything anyway. Laravel Nightwatch's own custom-range
     picker was checked directly for comparison: it turned out
     to pair this same kind of self-drawn date grid with a plain
     native time input for the time-of-day part. This component
     mirrors that split — a native time input's own time-of-day
     widget has the same restriction limits Flatpickr's did (a
     platform thing, not fixable from CSS/JS), so there was
     nothing to gain by building a custom one for that half.

     fromInstantMs/toInstantMs (real epoch ms, timezone-agnostic)
     are this component's actual state — the UTC/LOCAL/setting
     tabs are just different lenses onto the same two instants,
     converted to that lens's own wall-clock digits for display
     and back again on edit, rather than each tab owning its own
     separate value. --}}
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
        {{-- Seeded from the real current date below, in init() — never
             left null. The x-for/x-if nesting in the calendar grid below
             creates its :disabled bindings from whatever these are on
             first render; a later null → real-value jump (as opposed
             to a real-value → real-value one) failed to re-trigger
             those bindings' reactivity in testing, leaving every day
             permanently disabled the first time a calendar was opened
             until some *other* change happened to touch these two. --}}
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
        {{-- Sun-first short weekday names and the current calendar's
             month/year label, both via Intl rather than a hardcoded
             list or a new set of translation keys — Intl already
             knows every locale's own weekday/month names, and reads
             off the same app locale <html lang> already carries
             (see components/layout.blade.php), so this follows the
             dashboard's language setting automatically. --}}
        get weekdayLabels() {
            const fmt = new Intl.DateTimeFormat(@js(str_replace('_', '-', app()->getLocale())), { weekday: 'short' });

            return Array.from({ length: 7 }, (_, i) => fmt.format(new Date(Date.UTC(2023, 0, i + 1))));
        },
        get monthLabel() {
            const fmt = new Intl.DateTimeFormat(@js(str_replace('_', '-', app()->getLocale())), { month: 'long', year: 'numeric' });

            return fmt.format(new Date(this.calendarYear, this.calendarMonth, 1));
        },
        init() {
            {{-- Only knowable client-side — this is the visiting
                 browser's own offset, not anything DashboardController
                 could compute server-side. Sign-flipped: JS's
                 getTimezoneOffset() is minutes behind UTC (UTC+7
                 reports -420), the opposite of the +minutes-ahead
                 convention the timezoneOffsetMinutes prop above uses. --}}
            this.localOffsetMinutes = -new Date().getTimezoneOffset();

            {{-- $from/$to are already normalized server-side, always as
                 the setting-timezone's own wall clock (see Card::normalizeRange). --}}
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
        {{-- Sun-first grid — every cell is a real {day, month, year}
             (never null): the leading/trailing cells that spill into
             the adjacent month carry that month's own day numbers
             (adjacent: true) rather than being left blank, so the
             grid always fills every column (see dayCellClass). --}}
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
        {{-- min/max are real epoch ms (or null for "no bound"), coming from
             $fields' per-field 'min'/'max' JS source above — the
             hour-level 1h-gap rule is on the time inputs' own min (see
             minToTimeAttr) and apply()'s validation, not this grid. --}}
        isDayDisabled(cell, min, max) {
            const offset = this.offsetFor(this.mode);
            const startInstant = this.digitsToInstant({ Y: cell.year, M: cell.month, D: cell.day, H: 0, Min: 0 }, offset);
            const endInstant = this.digitsToInstant({ Y: cell.year, M: cell.month, D: cell.day, H: 23, Min: 59 }, offset);

            return (min !== null && endInstant < min) || (max !== null && startInstant > max);
        },
        {{-- A cell belonging to the adjacent month is dimmed but stays
             clickable (unless it's also out of min/max range) — same
             color as a disabled cell isn't used here on purpose, so
             the two states ("not this month" vs. "truly unpickable")
             stay visually distinct. --}}
        dayCellClass(cell, min, max, selected) {
            if (this.isDayDisabled(cell, min, max)) { return 'text-neutral-700 cursor-not-allowed'; }

            const isSelected = selected && cell.day === selected.D && cell.month === selected.M && cell.year === selected.Y;

            if (isSelected) { return 'bg-blue-600 text-white hover:bg-blue-600'; }

            return cell.adjacent ? 'text-neutral-600 hover:bg-neutral-700 hover:text-neutral-300' : 'text-neutral-200 hover:bg-neutral-700';
        },
        {{-- Picking a day does NOT close the dropdown — the time input
             lives inside this same overlay below, so the picker stays
             open until the user picks a time too or clicks away, same
             as Nightwatch's own combined date+time dropdown. A
             default time (current wall-clock time-of-day, in the
             active mode's offset) is filled in if this field
             doesn't have one yet, so the field is already
             complete even if the user never touches the time
             input at all. Picking an adjacent-month cell also
             slides the visible grid to that cell's own month,
             same as clicking there after navigating with the
             month arrows would. --}}
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
        {{-- The month-nav arrows are disabled, not just the days inside
             that month — e.g. if every day of next month is already
             beyond max (max is usually "now"), the arrow that would
             navigate there is disabled too rather than landing on an
             all-disabled month. --}}
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
        {{-- Native datetime-local's own input event value is already
             "YYYY-MM-DDTHH:mm" (empty string whenever the browser
             considers the current on-screen digits not a real
             date/time — either mid-edit on an incomplete segment,
             or a genuinely impossible combination like day 31 after
             arrowing the month to February) — parseRangeString
             already knows this exact format from parsing $from/$to
             above, so it's reused here rather than a second parser.

             Those two "empty" cases need different handling, and the
             event's own inputType is what tells them apart: the
             browser's arrow-key stepper replaces the whole value
             atomically ("insertReplacementText"), while a typed
             character is "insertText" (or similar). There is no API
             to read back which individual segment (day vs. month
             vs. year) produced an impossible date once the browser
             calls the whole value invalid, so a stepper-produced
             invalid value is snapped back to the last known-good
             one immediately — the only value the stepper can have
             landed on that isn't recoverable is thrown away rather
             than left on screen. A still-incomplete typed segment is
             left alone here (fixed up on blur instead, see the
             input's own blur handler below) — resetting mid-keystroke
             would fight normal typing. --}}
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
        {{-- The dropdown's own explicit time input below — a second,
             always-visible way to set the same fromTime/toTime the main
             datetime-local field's own segments also edit, kept
             alongside it rather than instead of it since the
             calendar dropdown is meant to offer date and time
             together in one place, same as Nightwatch's own
             combined date+time dropdown. --}}
        onTimeChange(which, value) {
            if (which === 'from') { this.fromTime = value; } else { this.toTime = value; }

            this.recompute(which);
        },
        {{-- The native input's own bound value/min/max attributes —
             fieldValue combines the separate …DateDigits/…Time state
             back into datetime-local's single string format;
             boundAttr does the same for a min/max instant (or null,
             meaning "no bound", which the input just won't get the
             attribute for). --}}
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
        {{-- Re-derives fromDateDigits/fromTime/toDateDigits/toTime from the
             canonical instants for the currently active mode's offset —
             after every edit and on every tab switch, which is what makes
             switching UTC/LOCAL/setting tabs show the same two instants
             converted into that tab's own wall clock instead of leaving
             stale digits behind. --}}
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
        {{-- Each check below is attributed to whichever field it's actually
             about — fromError/toError render right under that field's own
             box (see the @foreach below) rather than one shared message
             under both, so it's obvious at a glance which date needs
             fixing. Checks run in stages (badInput, then empty, then
             future, then the from/to relationship), stopping at the first
             stage that finds anything wrong — a field already flagged
             invalid at one stage skips being re-evaluated (and possibly
             contradicted) by a later one. --}}
        apply() {
            this.fromError = '';
            this.toError = '';

            {{-- badInput is true when the box has day/month/year typed in but
                 they don't name a real calendar date (31/02, 30/02, ...) —
                 checked first, since a field in that state also leaves
                 fromInstantMs/toInstantMs null (see the input's own comment
                 above) and would otherwise be misread as merely empty by
                 the next stage. --}}
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

            {{-- A from/to relationship, not either field alone — attributed
                 to "to" since it's naturally read as a property of the end
                 date ("end must be at least 1h after start"). --}}
            if (this.toInstantMs - this.fromInstantMs < 3600000) { this.toError = @js(__('monitor::messages.header.end_at_least_1h_after_start')); return; }

            const params = new URLSearchParams({
                from: this.formatRangeString(this.instantToDigits(this.fromInstantMs, {{ $timezoneOffsetMinutes }})),
                to: this.formatRangeString(this.instantToDigits(this.toInstantMs, {{ $timezoneOffsetMinutes }})),
            });
            window.location = '{{ route($currentRouteName, $currentRouteParams) }}?' + params.toString();
        },
        {{-- The configured monitor.timezone is the baseline — it's
             what every other timestamp on the dashboard is already
             shown in — so its tab always shows. UTC and Local only
             earn their own tab once they're actually a distinct
             offset from the ones already shown (comparing by
             current UTC offset rather than zone identifier, since
             e.g. Asia/Bangkok and Asia/Ho_Chi_Minh are both UTC+7
             right now and would otherwise show as two redundant
             tabs entering identical wall-clock values). Local is
             checked against both Setting and UTC — checking only
             Setting would, whenever Local happens to coincide with
             Setting instead, leave the switcher showing only UTC
             with no way back to Setting/Local's shared offset. --}}
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
         class="absolute right-0 top-full z-30 mt-2 w-64 rounded-lg bg-neutral-900 p-3 shadow-xl shadow-black/20">
        {{-- The setting-timezone tab below always renders unconditionally
             (see the comment above showUtcTab/showLocalTab) — this row
             is never empty, so no wrapping x-show is needed. --}}
        <div class="flex gap-0.5 rounded-md bg-neutral-800 p-0.5 font-mono text-xs">
            <button type="button" x-show="showUtcTab" @click="mode = 'utc'" class="flex-1 rounded px-2 py-1.5" :class="mode === 'utc' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">{{ __('monitor::messages.header.timezone_utc') }}</button>
            <button type="button" x-show="showLocalTab" @click="mode = 'local'" class="flex-1 rounded px-2 py-1.5" :class="mode === 'local' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">{{ __('monitor::messages.header.timezone_local') }}</button>
            {{-- Bounce-scrolls instead of just truncating with an
                 ellipsis when the label doesn't fit — see
                 .monitor-marquee in components/layout.blade.php.
                 The overflow distance is content-dependent, so
                 it's measured here rather than guessed in CSS.
                 x-effect (not x-init) because this button sits
                 inside the x-show="open" popover: at page load
                 that panel is still display:none, so measuring
                 once up front would only ever see a zero-size
                 box. Reading `open` here makes the effect rerun
                 every time the popover actually becomes visible;
                 $nextTick waits for that display change to have
                 already landed before measuring. data-tooltip
                 stays as a fallback (a touch device has no hover
                 to trigger the tooltip, but the animation runs
                 regardless of pointer type). --}}
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
        {{-- One field per row, showing date+time together — matches
             Laravel Nightwatch's own custom-range picker (see the note
             above the outer x-data): a single box with a calendar icon
             that, on click, opens one dropdown containing both the
             self-drawn date grid and a native time input together, not
             two separate always-visible boxes. Looped over $fields
             (defined above) instead of writing the "from" and "to" rows
             out twice — the two only ever differ in label/min/max. --}}
        @foreach ($fields as $which => $field)
            <div>
                <label class="mt-3 block text-xs text-neutral-400">{{ $field['label'] }}</label>
                <div class="relative mt-1">
                    {{-- The native input's own arrow-key stepper has no way to
                         skip an impossible day/month combination (e.g. day 31
                         held, stepping the month up from January to February,
                         which has no 31st) — there is no API to intervene in
                         its internal stepping logic from JS, only to react
                         after the fact. onDatetimeInput above snaps a
                         stepper-produced invalid combination back to the last
                         known-good value immediately (detected via the input
                         event's own inputType).

                         An incomplete/unparseable typed value is otherwise
                         left alone here — no reset on blur either — so it
                         stays on screen exactly as the user left it. apply()
                         is the only place that judges it, and reads
                         $refs.input{{ ucfirst($which) }}.validity.badInput to
                         tell "day/month/year filled in but not a real
                         calendar date" (e.g. 31/02) apart from "genuinely
                         empty" — a native datetime-local input reports its
                         own .value as '' for both, so onDatetimeInput can't
                         tell them apart from the parsed string alone, and
                         showing "pick both dates" for a visibly-filled-in
                         box that just happens to name an impossible day
                         reads as the app being wrong, not the input. --}}
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
                        {{-- Every cell always renders a real day number,
                             including the leading/trailing days that belong
                             to the adjacent month — those are just dimmed
                             (still clickable: picking one slides the visible
                             month to that day's own month), not blanked out.
                             A single <button> per cell (branching only its
                             class/disabled state), not two sibling
                             <template x-if> branches — Alpine's x-for
                             requires its immediate template child to be
                             exactly one root element, and splitting this
                             into two elements silently corrupted the whole
                             grid's reactivity once already. --}}
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
                        {{-- A second way to set the same time the main
                             field's own Hour/Minute segments also edit — the
                             dropdown is meant to offer date and time
                             together in one place, matching Laravel
                             Nightwatch's own combined date+time dropdown,
                             not just the date grid on its own. color-scheme
                             is set on this dropdown so the native time
                             input's own clock-icon chrome draws light-on-
                             dark instead of a near-invisible dark glyph. --}}
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
