{{-- HTML shell for every dashboard page: head assets, Tailwind config and the
     scripts that survive Livewire morphs. Mirrors Laravel's exception renderer
     layout component. --}}
@props(['title'])
@php($monitorTheme = \LaravelMonitor\Support\Preferences::theme())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $monitorTheme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Monitor</title>
    {{-- Resolve the theme before first paint so there is no light/dark flash. --}}
    <script>
        (function () {
            var root = document.documentElement;
            var pref = root.getAttribute('data-theme') || 'system';
            var media = window.matchMedia('(prefers-color-scheme: dark)');
            function apply() {
                var dark = pref === 'dark' || (pref === 'system' && media.matches);
                root.classList.toggle('dark', dark);
            }
            apply();
            media.addEventListener('change', function () { if (pref === 'system') apply(); });
            // Called by the settings page for instant preview before the form is saved.
            window.monitorApplyTheme = function (next) { pref = next; root.setAttribute('data-theme', next); apply(); };
        })();
    </script>
    {{-- Resolve the sidebar collapsed state before first paint (same reasoning
         as the theme script above) — otherwise the sidebar would render at
         full width, then jump to collapsed once Alpine hydrates. --}}
    <script>
        if (localStorage.getItem('monitor-nav-collapsed') === '1') {
            document.documentElement.setAttribute('data-nav-collapsed', '1');
        }
    </script>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/styles/github.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/highlight.min.js"></script>
    {{-- Pretty-prints SQL (one clause per line) before it's syntax-highlighted —
         exposes window.sqlFormatter.format(). See Requests\Timeline's
         sqlHighlighted(). --}}
    <script src="https://cdn.jsdelivr.net/npm/sql-formatter@4.0.2/dist/sql-formatter.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        {{-- shadow-top-* mirror Tailwind's own shadow-sm/DEFAULT/md/lg/xl values
             with the y-offsets negated, for elements that need a shadow cast
             upward (e.g. a sticky footer shadowing the content scrolled
             beneath it — components/navigation.blade.php's footer nav).
             Each still composes with the stock shadow-{color}/{opacity}
             utilities (shadow-black/5, dark:shadow-white/5, …) the same way
             shadow-lg does — Tailwind extracts the color from any boxShadow
             theme value, custom ones included, so that composability isn't
             something these have to opt into separately. --}}
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['InterVariable', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['"CommitMono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
                    },
                    boxShadow: {
                        'top-sm': '0 -1px 2px 0 rgb(0 0 0 / 0.05)',
                        top: '0 -1px 3px 0 rgb(0 0 0 / 0.1), 0 -1px 2px -1px rgb(0 0 0 / 0.1)',
                        'top-md': '0 -4px 6px -1px rgb(0 0 0 / 0.1), 0 -2px 4px -2px rgb(0 0 0 / 0.1)',
                        'top-lg': '0 -10px 15px -3px rgb(0 0 0 / 0.1), 0 -4px 6px -4px rgb(0 0 0 / 0.1)',
                        'top-xl': '0 -20px 25px -5px rgb(0 0 0 / 0.1), 0 -8px 10px -6px rgb(0 0 0 / 0.1)',
                    },
                },
            },
        };
    </script>
    <style>[x-cloak] { display: none !important; }</style>
    {{-- highlight.js only ships the light "github" theme (loaded above) — no
         dark variant exists to swap in, so override just the token colours
         that read poorly against a dark background instead of loading a
         second stylesheet. --}}
    <style>
        .dark .hljs-string { color: #60748c; }

        /* Firefox: thumb colour matched to neutral-300/neutral-700 (light/dark),
           transparent track so it doesn't add its own bar under the chart.
           Set on html (not body) since that's where .dark is toggled — a rule
           scoped to body would always win over an inherited value from html,
           leaving body's own scrollbar stuck light regardless of theme. */
        html {
            scrollbar-color: rgb(212 212 212) transparent;
        }
        html.dark {
            scrollbar-color: rgb(64 64 64) transparent;
        }
        /* Chromium/Safari: the default scrollbar otherwise stays light-themed
           (native OS chrome) even inside a dark-mode page. */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background-color: rgb(212 212 212);
            border-radius: 9999px;
        }
        /* ".dark ::-webkit-scrollbar-thumb" alone only matches descendants of
           .dark — it can't reach html's own scrollbar (the outer page/viewport
           bar), since html isn't a descendant of itself. Add the self-selector
           too so that one darkens as well. */
        .dark ::-webkit-scrollbar-thumb,
        html.dark::-webkit-scrollbar-thumb {
            background-color: rgb(64 64 64);
        }
    </style>
    {{-- Sidebar collapse: .monitor-nav-collapsed is toggled client-side by
         Alpine (components/navigation.blade.php); the html[data-nav-collapsed]
         selector covers the same result before Alpine hydrates (see the
         inline script above) so there's no width/label flash on load. --}}
    <style>
        .monitor-nav-aside {
            width: 228px;
            transition: width .15s ease;
        }
        .monitor-nav-aside.monitor-nav-collapsed,
        html[data-nav-collapsed] .monitor-nav-aside {
            width: 64px;
        }
        .monitor-nav-aside.monitor-nav-collapsed .monitor-nav-label,
        html[data-nav-collapsed] .monitor-nav-aside .monitor-nav-label {
            display: none;
        }
    </style>
    {{-- Bounce-scroll for a label too long for its box (e.g. the custom-range
         picker's setting-timezone tab, "Asia/Ho Chi Minh (UTC+7)") — sits at
         the start, scrolls left far enough to reveal the clipped end, sits
         there, scrolls back, repeat. The distance is content-dependent, so
         it's measured client-side (whoever applies .monitor-marquee sets
         --monitor-marquee-distance to -(overflow)px) rather than guessed here. --}}
    <style>
        @keyframes monitor-marquee-bounce {
            0%, 15% { transform: translateX(0); }
            50%, 65% { transform: translateX(var(--monitor-marquee-distance, 0px)); }
            100% { transform: translateX(0); }
        }
        .monitor-marquee {
            animation: monitor-marquee-bounce 6s ease-in-out infinite;
        }
    </style>
    {{-- Shimmer sweep for skeleton loading bars (components/table-skeleton.blade.php):
         a light gradient band slides left-to-right over the bar's flat
         background-color on a loop. Layered as background-image on top of
         Tailwind's bg-neutral-100/dark:bg-neutral-800 (a separate CSS
         property, so both coexist) rather than replacing those utilities. --}}
    <style>
        @keyframes monitor-skeleton-shimmer {
            0% { background-position: -150% 0; }
            100% { background-position: 250% 0; }
        }
        .monitor-skeleton {
            background-image: linear-gradient(90deg, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, .7) 50%, rgba(255, 255, 255, 0) 100%);
            background-size: 60% 100%;
            background-repeat: no-repeat;
            animation: monitor-skeleton-shimmer 1.6s ease-in-out infinite;
        }
        .dark .monitor-skeleton {
            background-image: linear-gradient(90deg, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, .12) 50%, rgba(255, 255, 255, 0) 100%);
        }
    </style>
    {{-- Global tooltip skin: every data-tooltip="…" attribute in the app
         renders as this dark box, matching the
         one components/requests/timeline-row.blade.php already draws by
         hand for its own bar-hover detail (same bg-neutral-900/border-
         neutral-700/text-neutral-100 combo, deliberately not dark:-gated —
         that tooltip doesn't follow the page theme either). Plain CSS, not
         Tailwind utility classes, since this element is created by the
         script below rather than written in Blade — see the same reasoning
         on .monitor-nav-aside just above. --}}
    <style>
        .monitor-global-tooltip {
            position: fixed;
            z-index: 60;
            max-width: 28rem;
            padding: .375rem .625rem;
            border-radius: .375rem;
            border: 1px solid rgb(64 64 64);
            background-color: rgb(23 23 23);
            color: rgb(245 245 245);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 11px;
            line-height: 1.625;
            white-space: pre-wrap;
            word-break: break-word;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / .1), 0 4px 6px -4px rgb(0 0 0 / .1);
            pointer-events: none;
            opacity: 0;
            visibility: hidden;
            transition: opacity .1s ease;
        }
        .monitor-global-tooltip.is-visible {
            opacity: 1;
            visibility: visible;
        }
    </style>
    @livewireStyles
</head>
<body class="min-h-screen bg-neutral-50 font-sans text-neutral-900 antialiased dark:bg-neutral-950 dark:text-neutral-100">
    {{ $slot }}

    <x-monitor::toast-container/>

    @livewireScripts

    {{-- Progressive syntax highlighting for stack-trace snippets (highlight.js),
         re-applied after every Livewire poll/morph so it survives DOM patches. --}}
    <script>
        (function () {
            function highlight() {
                if (! window.hljs) return;
                document.querySelectorAll('[data-line-code]').forEach(function (el) {
                    var language = el.dataset.lang || 'php';
                    el.innerHTML = window.hljs.highlight(el.textContent, { language: language, ignoreIllegals: true }).value;
                });
            }

            function hookLivewire() {
                if (! window.Livewire) return;
                window.Livewire.hook('morphed', highlight);
                window.Livewire.hook('morph', highlight);
            }

            window.addEventListener('load', highlight);
            document.addEventListener('livewire:init', hookLivewire);
            document.addEventListener('livewire:navigated', highlight);
            hookLivewire();
        })();
    </script>

    {{-- Any Livewire round trip that fails — an action call or a wire:poll
         cycle alike — pops a toast instead of failing silently. wire:poll in
         particular gives no other visible sign a refresh cycle didn't land:
         the page just quietly stops updating until the next successful poll.
         Livewire.hook('request', …) is registered once, globally, so this
         covers every component's polls/actions without each one wiring its
         own handler. preventDefault() is deliberately never called — this
         only adds the toast on top of Livewire's own default failure
         handling (e.g. its debug-mode error overlay), it doesn't replace it. --}}
    <script>
        (function () {
            function hookLivewire() {
                if (! window.Livewire) return;
                window.Livewire.hook('request', function ({ fail }) {
                    fail(function () {
                        window.dispatchEvent(new CustomEvent('toast', {
                            detail: { level: 'danger', message: @js(__('monitor::messages.common.update_failed')) },
                        }));
                    });
                });
            }

            document.addEventListener('livewire:init', hookLivewire);
            hookLivewire();
        })();
    </script>

    {{-- Every x-monitor::refresh-button reads this one store: it spins the
         icon and no-ops the click while a round trip is in flight, for
         *any* component's poll/action — not just the one the button
         belongs to — since a single global flag is what "all refresh
         buttons spin together" means. Registered on alpine:init rather
         than lazily inside some component's x-data (the monitorClock
         pattern in refresh-ring.blade.php) because refresh-button reads it
         immediately on first paint, before any such component could have
         run its init().

         `active` is a getter over a round-trip *count*, not a plain boolean —
         the dashboard Overview embeds three independently-polling
         components at once (Overview/Application/Users cards), so their
         requests overlap rather than running one at a time. A boolean set true-per-start/false-per-finish would go false
         the instant the FIRST of several concurrent requests lands, even
         with others still in flight — cutting every refresh-ring's spin
         short and having refresh-button's click guard drop early. Counting
         only reaches zero (active: false) once every concurrent request has
         actually finished. --}}
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.store('monitorPolling', {
                count: 0,
                get active() { return this.count > 0; },
            });

            {{-- When the last in-flight request settled. Drives both the
                 auto-refresh timer below and every refresh-ring's countdown,
                 so the two can't disagree. --}}
            Alpine.store('monitorRefreshClock', { startedAt: Math.floor(Date.now() / 1000) });

            {{-- The 1s heartbeat every countdown/refresh-ring/relative-time
                 on the page shares, in whole seconds — registered here rather
                 than lazily by whichever of those hydrates first, so there is
                 one definition of it. Those components keep their own lazy
                 fallback for rendering outside this layout.

                 relative-time used to run a setInterval per cell instead:
                 each started when its own cell hydrated, so a table advanced
                 at as many sub-second phases as it had rows, every digit
                 flipping on its own beat. One tick confines every change to
                 the same instants, and runs one timer rather than one per
                 visible timestamp.

                 Aligned to the wall-clock second so the first tick after page
                 load isn't a partial one. --}}
            Alpine.store('monitorClock', { now: Math.floor(Date.now() / 1000) });

            setTimeout(function () {
                Alpine.store('monitorClock').now = Math.floor(Date.now() / 1000);
                setInterval(() => (Alpine.store('monitorClock').now = Math.floor(Date.now() / 1000)), 1000);
            }, 1000 - (Date.now() % 1000));

            {{-- A background tab throttles the interval above to a crawl, so
                 every timestamp is however stale on return: catch them up on
                 the spot rather than a tick later. --}}
            document.addEventListener('visibilitychange', function () {
                if (! document.hidden) {
                    Alpine.store('monitorClock').now = Math.floor(Date.now() / 1000);
                }
            });
        });

        (function () {
            function settled() {
                if (--Alpine.store('monitorPolling').count === 0) {
                    Alpine.store('monitorRefreshClock').startedAt = Math.floor(Date.now() / 1000);
                }
            }

            {{-- The 'commit' hook's respond(), not 'request''s succeed/fail:
                 those two only fire once a network response arrives, so a
                 message Livewire cancels or squashes into another never
                 decrements and the count wedges above zero for good — which
                 now jams auto-refresh, not just the spinner. respond() is
                 wired to the message's onFinish, which runs on success,
                 error, cancel and squash alike. --}}
            function hookLivewire() {
                if (! window.Livewire) return;
                window.Livewire.hook('commit', function ({ respond }) {
                    Alpine.store('monitorPolling').count++;
                    respond(settled);
                });
            }

            document.addEventListener('livewire:init', hookLivewire);
            hookLivewire();
        })();

        {{-- Auto-refresh driver for every data-monitor-poll root, replacing
             wire:poll — whose interval is a page-global setInterval Livewire
             never restarts, so a manual refresh or a filter change left it
             firing early, mid-countdown. --}}
        (function () {
            const interval = {{ (int) config('monitor.refresh', 10) }};
            let timer = null;

            function nowSeconds() { return Math.floor(Date.now() / 1000); }

            {{-- Delay measured against Date.now(), not a second-floored "now":
                 flooring both ends rounds the wait up by up to a second every
                 cycle. --}}
            function schedule() {
                clearTimeout(timer);
                const dueAt = (Alpine.store('monitorRefreshClock').startedAt + interval) * 1000;
                timer = setTimeout(tick, Math.max(1000, dueAt - Date.now()));
            }

            function tick() {
                {{-- Deliberately re-arms without re-anchoring: that keeps the
                     page due, so schedule()'s 1s floor retries until the tab
                     is back rather than waiting out a whole fresh interval. --}}
                if (document.hidden || ! navigator.onLine || Alpine.store('monitorPolling').active) {
                    return schedule();
                }

                document.querySelectorAll('[data-monitor-poll]').forEach(function (el) {
                    const wire = window.Livewire.find(el.getAttribute('wire:id'));

                    if (wire) wire.$refresh();
                });

                {{-- Re-anchored again when those requests settle; doing it
                     here too keeps the timer armed even if none resolved. --}}
                Alpine.store('monitorRefreshClock').startedAt = nowSeconds();
            }

            {{-- schedule() reads startedAt, so the effect re-runs — re-arming
                 the timer — every time anything re-anchors the clock. --}}
            document.addEventListener('alpine:initialized', function () {
                Alpine.effect(schedule);
            });
        })();
    </script>

    {{-- Renders every data-tooltip="…" in the app as the dark box above,
         instead of each of the ~50 views that set one drawing its own —
         same idea as components/requests/timeline-row.blade.php's own
         hand-drawn bar tooltip, generalised. Delegated on document (not
         bound per-element at load) so it also covers rows/labels that
         appear later — a Livewire poll swapping in a new table row, a
         filter re-render, anything wire:poll morphs in. mouseover/mouseout
         are used rather than mouseenter/mouseleave because only the former
         bubble, which delegation requires; the relatedTarget checks below
         are what keep them from re-firing on every inner element the way
         plain bubbling would.

         Unlike a plain title="…", this is a custom attribute the browser
         never touches on its own, so there's no native tooltip to fight —
         no need to strip/restore anything the way an earlier version of
         this script had to when it was still hijacking title directly.
         That also means the bound element can keep updating its own
         data-tooltip live (see refresh-ring.blade.php's :data-tooltip,
         which ticks every second) — the MutationObserver below re-reads it
         on change so the box reflects that instead of freezing at
         whatever the value was on mouseenter. --}}
    <script>
        (function () {
            var tip = null;
            var activeEl = null;
            var observer = null;

            function ensureTip() {
                if (! tip) {
                    tip = document.createElement('div');
                    tip.className = 'monitor-global-tooltip';
                    tip.setAttribute('role', 'tooltip');
                    document.body.appendChild(tip);
                }

                return tip;
            }

            function position(el) {
                var rect = el.getBoundingClientRect();
                var tipEl = ensureTip();
                // A `position: fixed` box with `width: auto` shrink-to-fits
                // against "viewport width minus its own current left" — a
                // *live* recalculation on every layout pass, not a one-off
                // taken at measurement time here. Resetting `left` to 0
                // before measuring (so this first pass reflects the content
                // alone, not whatever `left` a previous tooltip left behind)
                // isn't enough by itself: the *final* `left` set below is
                // clamped to leave only a slim 4px margin past the
                // measured width, and this box keeps recomputing
                // shrink-to-fit against that narrower "available width" on
                // every later reflow (a resize, a scroll-triggered repaint,
                // even just this box's own opacity transition) — a shorter
                // measured width there re-wraps the text, which then
                // measures shorter still next time, visibly collapsing a
                // one-line tooltip into a dozen. Freezing the box at an
                // explicit pixel width once, from this unconstrained
                // measurement, stops it from ever shrink-to-fitting again.
                tipEl.style.width = 'auto';
                tipEl.style.left = '0px';
                var tipRect = tipEl.getBoundingClientRect();
                tipEl.style.width = tipRect.width + 'px';
                var left = rect.left + rect.width / 2 - tipRect.width / 2;
                left = Math.max(4, Math.min(left, window.innerWidth - tipRect.width - 4));
                var top = rect.top - tipRect.height - 6;

                if (top < 4) {
                    top = rect.bottom + 6;
                }

                tip.style.left = left + 'px';
                tip.style.top = top + 'px';
            }

            function render(el) {
                var text = el.getAttribute('data-tooltip');

                if (! text) {
                    return false;
                }

                var el2 = ensureTip();
                el2.textContent = text;
                position(el);

                return true;
            }

            function show(el) {
                if (! render(el)) {
                    return;
                }

                activeEl = el;
                tip.style.visibility = 'visible';
                requestAnimationFrame(function () { tip.classList.add('is-visible'); });

                observer = new MutationObserver(function () {
                    // The value can go empty mid-hover (a conditional wrapping the
                    // attribute in the source flips) — treat that as a hide
                    // rather than leaving a stale/blank box up.
                    if (! render(el)) {
                        hide();
                    }
                });
                observer.observe(el, { attributes: true, attributeFilter: ['data-tooltip'] });
            }

            function hide() {
                if (! activeEl) {
                    return;
                }

                if (observer) {
                    observer.disconnect();
                    observer = null;
                }

                activeEl = null;

                if (tip) {
                    tip.classList.remove('is-visible');
                }
            }

            document.addEventListener('mouseover', function (e) {
                var el = e.target.closest('[data-tooltip]');

                if (! el || el === activeEl) {
                    return;
                }

                if (activeEl) {
                    hide();
                }

                show(el);
            });

            document.addEventListener('mouseout', function (e) {
                if (activeEl && ! activeEl.contains(e.relatedTarget)) {
                    hide();
                }
            });

            document.addEventListener('focusin', function (e) {
                var el = e.target.closest('[data-tooltip]');

                if (el && el !== activeEl) {
                    if (activeEl) {
                        hide();
                    }

                    show(el);
                }
            });

            document.addEventListener('focusout', function (e) {
                if (activeEl && (! e.relatedTarget || ! activeEl.contains(e.relatedTarget))) {
                    hide();
                }
            });

            // A poll/morph can remove the hovered element outright (a row
            // drops out of a list) — nothing then fires mouseout, so hide
            // before the morph applies rather than leave a stale tooltip
            // pointing at a node that's about to disappear or move.
            document.addEventListener('livewire:init', function () {
                window.Livewire.hook('morph', hide);
            });
        })();
    </script>
</body>
</html>
