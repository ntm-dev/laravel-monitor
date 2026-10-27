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
    {{-- Resolve the sidebar collapsed state before first paint, so it doesn't jump on hydration. --}}
    <script>
        if (localStorage.getItem('monitor-nav-collapsed') === '1') {
            document.documentElement.setAttribute('data-nav-collapsed', '1');
        }
    </script>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/styles/github.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/highlight.min.js"></script>
    {{-- window.sqlFormatter.format(), used by timeline-script.blade.php's sqlHighlighted(). --}}
    <script src="https://cdn.jsdelivr.net/npm/sql-formatter@4.0.2/dist/sql-formatter.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        {{-- shadow-top-*: Tailwind's shadow sizes with negated y-offsets, for upward shadows
             (e.g. the sidebar footer). They still compose with shadow-color utilities. --}}
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
    {{-- Dark-mode overrides for the light highlight.js theme, plus themed scrollbars. --}}
    <style>
        .dark .hljs-string { color: #60748c; }

        /* Firefox: set on html, where .dark is toggled. */
        html {
            scrollbar-color: rgb(212 212 212) transparent;
        }
        html.dark {
            scrollbar-color: rgb(64 64 64) transparent;
        }
        /* Chromium/Safari. */
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
        /* html.dark covers the viewport scrollbar, which ".dark ::" (descendants only) misses. */
        .dark ::-webkit-scrollbar-thumb,
        html.dark::-webkit-scrollbar-thumb {
            background-color: rgb(64 64 64);
        }
    </style>
    {{-- Sidebar collapse: Alpine toggles .monitor-nav-collapsed; html[data-nav-collapsed] covers pre-hydration. --}}
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
    {{-- Bounce-scroll for an overflowing label. Whoever applies .monitor-marquee sets
         --monitor-marquee-distance to the negative overflow in px. --}}
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
    {{-- Shimmer for skeleton bars (table-skeleton.blade.php), layered as background-image over their bg colour. --}}
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
    {{-- Entrance wave for the sign-in card (auth/login.blade.php). --}}
    <style>
        @keyframes monitor-rotational-wave {
            0% { opacity: 0; transform: translateY(calc(16px * var(--wave-dir, 1))) rotate(calc(-3deg * var(--wave-dir, 1))); }
            40% { transform: translateY(calc(-4px * var(--wave-dir, 1))) rotate(calc(1.5deg * var(--wave-dir, 1))); }
            70% { transform: translateY(calc(2px * var(--wave-dir, 1))) rotate(calc(-.5deg * var(--wave-dir, 1))); }
            100% { opacity: 1; transform: translateY(0) rotate(0); }
        }
        .monitor-rotational-wave {
            animation: monitor-rotational-wave 1s ease-out both;
        }
        .monitor-rotational-wave-reverse {
            --wave-dir: -1;
        }
        @media (prefers-reduced-motion: reduce) {
            .monitor-rotational-wave { animation: none; }
        }
    </style>
    {{-- One-shot bounce that starts and ends at rest (Tailwind's bounce starts displaced, so it jumps when not looped). --}}
    <style>
        @keyframes monitor-bounce-once {
            0%, 100% { transform: translateY(0); animation-timing-function: cubic-bezier(.2, 0, .6, 1); }
            35% { transform: translateY(-16px); animation-timing-function: cubic-bezier(.4, 0, 1, 1); }
            65% { transform: translateY(0); animation-timing-function: cubic-bezier(.2, 0, .6, 1); }
            82% { transform: translateY(-5px); animation-timing-function: cubic-bezier(.4, 0, 1, 1); }
        }
        .monitor-bounce-once {
            animation: monitor-bounce-once 1s both;
        }
        @media (prefers-reduced-motion: reduce) {
            .monitor-bounce-once { animation: none; }
        }
    </style>
    {{-- Global data-tooltip box, dark in both themes. Plain CSS since the script below creates the element. --}}
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

    {{-- Toast on any failed Livewire round trip (action or poll). Doesn't call preventDefault(),
         so Livewire's own failure handling still runs. --}}
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

    {{-- Global in-flight flag read by every refresh button/ring, registered on alpine:init so it
         exists on first paint. `active` counts round trips, since several components poll concurrently. --}}
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.store('monitorPolling', {
                count: 0,
                get active() { return this.count > 0; },
            });

            {{-- When the last in-flight request settled; drives both the auto-refresh timer and refresh-ring. --}}
            Alpine.store('monitorRefreshClock', { startedAt: Math.floor(Date.now() / 1000) });

            {{-- Shared 1s tick (whole seconds) for countdown, refresh-ring and relative-time, aligned
                 to the wall-clock second. Those components keep a lazy fallback outside this layout. --}}
            Alpine.store('monitorClock', { now: Math.floor(Date.now() / 1000) });

            setTimeout(function () {
                Alpine.store('monitorClock').now = Math.floor(Date.now() / 1000);
                setInterval(() => (Alpine.store('monitorClock').now = Math.floor(Date.now() / 1000)), 1000);
            }, 1000 - (Date.now() % 1000));

            {{-- Background tabs throttle the interval; catch up immediately on return. --}}
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

            {{-- Decrement in the commit hook's respond(), which also runs for cancelled/squashed
                 messages; request's succeed/fail don't, which would wedge the count. --}}
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

        {{-- Auto-refresh driver for every data-monitor-poll root, timed off monitorRefreshClock. --}}
        (function () {
            const interval = {{ (int) config('monitor.refresh', 10) }};
            let timer = null;

            function nowSeconds() { return Math.floor(Date.now() / 1000); }

            {{-- Measured against unfloored Date.now() so the wait isn't rounded up each cycle. --}}
            function schedule() {
                clearTimeout(timer);
                const dueAt = (Alpine.store('monitorRefreshClock').startedAt + interval) * 1000;
                timer = setTimeout(tick, Math.max(1000, dueAt - Date.now()));
            }

            function tick() {
                {{-- Re-arm without re-anchoring, so it retries every 1s until the page can refresh. --}}
                if (document.hidden || ! navigator.onLine || Alpine.store('monitorPolling').active) {
                    return schedule();
                }

                document.querySelectorAll('[data-monitor-poll]').forEach(function (el) {
                    const wire = window.Livewire.find(el.getAttribute('wire:id'));

                    if (wire) wire.$refresh();
                });

                {{-- Also re-anchored when the requests settle; this keeps the timer armed if none do. --}}
                Alpine.store('monitorRefreshClock').startedAt = nowSeconds();
            }

            {{-- schedule() reads startedAt, so the effect re-arms whenever the clock re-anchors. --}}
            document.addEventListener('alpine:initialized', function () {
                Alpine.effect(schedule);
            });
        })();
    </script>

    {{-- Renders every data-tooltip in the app, delegated on document (mouseover/mouseout bubble)
         so morphed-in elements are covered. A MutationObserver picks up live data-tooltip changes. --}}
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
                // Measure at left 0, then freeze the width: a fixed auto-width box keeps
                // shrink-to-fitting against its clamped left and collapses its text.
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
                    // Hide if the value goes empty mid-hover.
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

            // A morph can remove the hovered element without a mouseout, so hide first.
            document.addEventListener('livewire:init', function () {
                window.Livewire.hook('morph', hide);
            });
        })();
    </script>
</body>
</html>
