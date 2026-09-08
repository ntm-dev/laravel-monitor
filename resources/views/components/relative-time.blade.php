{{-- Client-side ticking "X ago" — the server-rendered text (from Carbon's
     own diffForHumans(short: true), so it matches everywhere else in the
     dashboard on first paint) only updates when the page's own auto-refresh
     fires. Once Alpine hydrates, this instead recomputes on every tick of the
     shared monitorClock store (see components/layout.blade.php) from the raw
     timestamp, using the browser's native Intl.RelativeTimeFormat — so a
     count in seconds keeps advancing every second, one in minutes every
     minute, and so on, without reimplementing Carbon's per-locale
     pluralization rules in JS.

     'narrow' + numeric:'always' is what makes Intl's output identical to
     Carbon's short form ("45m ago", "45 phút trước") — the default 'long'
     style renders "45 minutes ago" instead, so every cell Alpine had already
     ticked disagreed with every freshly rendered one. numeric:'always' also
     keeps Intl from swapping in "yesterday"/"Hôm kia" where Carbon prints
     "1d ago".

     Each unit truncates the raw seconds, matching Carbon (90s is "1m ago" to
     it, not "2m ago"): rounding instead made every cell flip by one unit a
     second after each refresh overwrote it with the server's own value. --}}
@props(['at', 'locale' => null])
{{-- The timestamp lives in a data attribute, not in Alpine's own state:
     Livewire's morph updates a reused element's attributes but leaves the
     Alpine island's contents alone, so a component that froze atMs at init
     kept rendering the previous row's time after a sort, a page change or an
     auto-refresh. tick() re-reads it on every clock tick instead, and watch()
     re-runs it as soon as a morph rewrites the attribute so the cell never
     shows the old row's time until the next one. --}}
<span data-at="{{ $at->getTimestamp() * 1000 }}"
      x-data="{
        locale: @js($locale ?? app()->getLocale()),
        text: @js($at->diffForHumans(short: true)),
        frame: null,
        /**
         * A morph rewrites a row's attributes and its text in separate steps,
         * so mid-morph this cell briefly pairs the incoming row's data-at
         * with the outgoing row's text. Coalescing to the next frame runs
         * tick() once the whole morph has settled, rather than rendering that
         * intermediate pair for a second.
         */
        watch() {
            new MutationObserver(() => {
                cancelAnimationFrame(this.frame);
                this.frame = requestAnimationFrame(() => this.tick());
            }).observe(this.$el, { attributes: true, attributeFilter: ['data-at'] });
        },
        {{-- `nowSeconds` is the shared monitorClock's whole-second tick;
             absent (no store, or a re-run off a morph) it falls back to the
             live clock. --}}
        tick(nowSeconds) {
            const nowMs = nowSeconds === undefined ? Date.now() : nowSeconds * 1000;
            const diffSec = Math.trunc((nowMs - Number(this.$el.dataset.at)) / 1000);
            const rtf = new Intl.RelativeTimeFormat(this.locale, { numeric: 'always', style: 'narrow' });
            const abs = Math.abs(diffSec);
            this.text = abs < 60 ? rtf.format(-diffSec, 'second')
                : abs < 3600 ? rtf.format(-Math.trunc(diffSec / 60), 'minute')
                : abs < 86400 ? rtf.format(-Math.trunc(diffSec / 3600), 'hour')
                : rtf.format(-Math.trunc(diffSec / 86400), 'day');
        },
     }"
     x-init="watch()"
     x-effect="tick($store.monitorClock?.now)"
     x-text="text">{{ $at->diffForHumans(short: true) }}</span>
