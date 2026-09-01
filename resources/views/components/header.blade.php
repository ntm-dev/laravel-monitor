{{-- Sticky page header: detail breadcrumb or page title, period switcher with
     custom range picker, and the mobile tab strip. All data is prepared by
     Http\Controllers\DashboardController. Optional slots: $leading renders
     before the page title, $periodsExtra renders in the period switcher's
     spot (alongside or instead of it). --}}
@props(['tab', 'tabs', 'groups', 'title', 'detail', 'key', 'range', 'period', 'periods', 'hasCustomRange', 'from', 'to', 'timezone', 'rangeMax', 'currentRouteName', 'currentRouteParams'])
@php
    // $timezone (the prop above) is Format::timezone() — already a display
    // offset string like "+07:00", not the zone identifier — no good for
    // either DateTimeZone or a "Asia/Ho_Chi_Minh"-style label below, so this
    // block fetches the real identifier straight from Preferences instead.
    $timezoneIdentifier = \LaravelMonitor\Support\Preferences::timezone();
    // Same computation as Preferences::timezoneOptions() — the custom-range
    // picker's UTC/Local/setting tabs are deduplicated by current UTC
    // offset, not by zone identifier: two identifiers can share the same
    // offset right now (e.g. Asia/Bangkok and Asia/Ho_Chi_Minh are both
    // UTC+7 with no DST), and comparing names would then show two tabs that
    // enter the exact same wall-clock values.
    $timezoneOffsetSeconds = (new \DateTimeZone($timezoneIdentifier))->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
    $timezoneOffsetMinutes = intdiv($timezoneOffsetSeconds, 60);
    // "Asia/Ho Chi Minh (UTC+7)" — name plus offset, same "name"/"offset"
    // pieces (and the same underscore-to-space cleanup) as Settings' own
    // timezone picker (Preferences::timezoneOptions()).
    $timezoneLabel = str_replace('_', ' ', $timezoneIdentifier).' ('.\LaravelMonitor\Support\Preferences::formatOffset($timezoneOffsetSeconds).')';
@endphp
<header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur dark:bg-neutral-950/80">
    <div class="mx-auto flex w-full max-w-[1600px] items-center justify-between gap-4 px-4 py-5 md:px-8">
        @if ($detail !== null)
            <div class="min-w-0">
                <a href="{{ route('monitor.dashboard', ['tab' => $tab] + $range) }}" class="text-xs text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">{{ $tabs[$tab]['label'] }}</a>
                @if ($detail->badge !== null || $detail->heading !== null)
                    <div class="mt-0.5 flex min-w-0 gap-2.5 {{ $detail->wrap ? 'items-start' : 'items-center' }}">
                        @if (! $detail->badgeAfter && $detail->badge !== null)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $detail->badgeClass }}">{{ $detail->badge }}</span>
                        @endif
                        @if ($detail->heading !== null)
                            <h1 class="{{ $detail->wrap ? 'whitespace-pre-wrap break-words font-mono text-base font-semibold' : 'truncate text-2xl font-bold' }} tracking-tight" @if ($detail->titleAttr) data-tooltip="{{ $detail->titleAttr }}" @endif>{{ $detail->heading }}</h1>
                        @endif
                        @if ($detail->badgeAfter && $detail->badge !== null)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight {{ $detail->badgeClass }}">{{ $detail->badge }}</span>
                        @endif
                    </div>
                @endif
            </div>
        @else
            <div class="flex min-w-0 items-center gap-2.5">
                @isset($leading)
                    {{ $leading }}
                @endisset
                <h1 class="truncate text-2xl font-bold tracking-tight">{{ $title }}</h1>
            </div>
        @endif

        @if (! in_array($tab, ['settings', 'team', 'issues'], true))
        <div class="flex h-8 shrink-0 items-center gap-0.5 rounded-lg border border-neutral-200 bg-white p-0.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            @foreach ($periods as $value)
                <a href="{{ route($currentRouteName, $currentRouteParams + array_filter(['period' => $value])) }}"
                   @class([
                       'flex h-full min-w-8 items-center justify-center rounded-md border px-2.5 font-mono text-xs',
                       'border-blue-500 bg-blue-600 text-white' => ! $hasCustomRange && $period === $value,
                       'border-transparent text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' => $hasCustomRange || $period !== $value,
                   ])>{{ strtoupper($value) }}</a>
            @endforeach
            <span class="mx-0.5 h-4 w-px bg-neutral-200 dark:bg-neutral-700"></span>
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
                         left null. Alpine's x-for/x-if nesting in the calendar
                         grid (components/datetime-picker.blade.php) creates its
                         :disabled bindings from whatever these are on
                         first render; a later null → real-value jump (as opposed
                         to a real-value → real-value one) failed to re-trigger
                         those bindings' reactivity in testing, leaving every day
                         permanently disabled the first time a calendar was opened
                         until some *other* change happened to touch these two. --}}
                    calendarYear: null,
                    calendarMonth: null,
                    error: '',
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
                             convention the php block above uses for timezoneOffsetMinutes. --}}
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
                    {{-- min/max are real epoch ms (or null for "no bound"), passed
                         in by the datetime-picker component rather than hardcoded
                         here — the hour-level 1h-gap rule is on the time inputs'
                         own min (see minToTimeAttr) and apply()'s validation, not
                         this grid. --}}
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
                    {{-- Picking a day does NOT close the dropdown — the time
                         input lives inside this same overlay (see
                         datetime-picker.blade.php), so the picker stays open
                         until the user picks a time too or clicks away, same
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
                         input's own blur handler in datetime-picker.blade.php) —
                         resetting mid-keystroke would fight normal typing. --}}
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
                    {{-- The dropdown's own explicit time input (see
                         datetime-picker.blade.php) — a second, always-visible
                         way to set the same fromTime/toTime the main
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
                    apply() {
                        if (this.fromInstantMs === null || this.toInstantMs === null) { this.error = @js(__('monitor::messages.header.pick_both_dates')); return; }

                        const now = Date.now();

                        if (this.fromInstantMs > now || this.toInstantMs > now) { this.error = @js(__('monitor::messages.header.no_future_dates')); return; }
                        if (this.toInstantMs - this.fromInstantMs < 3600000) { this.error = @js(__('monitor::messages.header.end_at_least_1h_after_start')); return; }

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
                         Laravel Nightwatch's own custom-range picker (see the
                         note above the outer x-data): a single box with a
                         calendar icon that, on click, opens one dropdown
                         containing both the self-drawn date grid and a native
                         time input together (see datetime-picker.blade.php),
                         not two separate always-visible boxes. min/max are raw
                         JS expressions (not PHP values) evaluated against this
                         x-data — see the prop docs at the top of
                         datetime-picker.blade.php. --}}
                    <x-monitor::datetime-picker which="from" label="{{ __('monitor::messages.header.starting_date') }}" min="null" max="Date.now()"/>
                    <x-monitor::datetime-picker which="to" label="{{ __('monitor::messages.header.ending_date') }}" min="fromInstantMs !== null ? fromInstantMs + 3600000 : null" max="Date.now()"/>
                    <p x-show="error" x-text="error" class="mt-2 text-xs text-rose-400"></p>
                    <button type="button" @click="apply()"
                            class="mt-3 w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">{{ __('monitor::messages.header.apply') }}</button>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Mobile navigation --}}
    <nav class="flex gap-1 overflow-x-auto px-4 pb-2 text-xs md:hidden">
        @foreach ($groups as $items)
            @foreach ($items as $tabKey => $item)
                <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                   @class([
                       'shrink-0 rounded-md border px-2.5 py-1.5',
                       'border-neutral-200 bg-white text-neutral-900 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100' => $tab === $tabKey,
                       'border-transparent text-neutral-500 dark:text-neutral-400' => $tab !== $tabKey,
                   ])>{{ $item['label'] }}</a>
            @endforeach
        @endforeach
    </nav>
</header>
