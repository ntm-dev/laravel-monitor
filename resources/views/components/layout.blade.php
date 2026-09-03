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
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['InterVariable', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['"CommitMono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
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
                var tipRect = ensureTip().getBoundingClientRect();
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
